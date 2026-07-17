<?php

/**
 * ident_switch - Account switching handler.
 *
 * Manages switching between mail accounts, SMTP connection
 * configuration, and special folder assignments.
 *
 * Copyright (C) 2016-2022 Boris Gulay
 * Copyright (C) 2019      Christian Landvogt
 * Copyright (C) 2022      Mickael
 * Copyright (C) 2026      Gecka
 *
 * Original code licensed under GPL-3.0+.
 * New contributions licensed under AGPL-3.0+.
 *
 * @url https://github.com/Gecka-apps/ident_switch
 */
class IdentSwitchSwitcher
{
    public function __construct(private readonly IdentSwitchCredentialService $credentials)
    {
    }

    /**
     * Handle the account switch action (AJAX).
     *
     * Saves current account state to session, loads the target account's
     * IMAP/SMTP configuration, and redirects to INBOX.
     * When switching back to default (id=-1), restores the original session state.
     */
    public function switch_account(): void
    {
        $identId = (int)rcube_utils::get_input_value('_ident-id', rcube_utils::INPUT_POST);
        $this->switchAccountById($identId);
    }

    /**
     * Switch to an account already authorized for the current Roundcube user.
     *
     * This is the trusted API used by managed preferred-account selection. The
     * same ownership-constrained query is shared with the browser action.
     */
    public function switchAccountById(int $identId, bool $redirect = true): bool
    {
        $rc = rcmail::get_instance();
        $my_postfix_len = strlen(ident_switch::MY_POSTFIX);

        if ($identId === -1) {
            // Switch to main account
            ident_switch::write_log('Switching mailbox back to default.');

            $rc->session->remove('folders');
            $rc->session->remove('unseen_count');
            $this->reset_baseline(0, $rc, $identId);

            // Restore everything with STORAGE*my_postfix
            foreach ($_SESSION as $k => $v) {
                if (str_starts_with(strtolower($k), 'storage') && str_ends_with($k, ident_switch::MY_POSTFIX)) {
                    $realKey = substr($k, 0, -$my_postfix_len);
                    $_SESSION[$realKey] = $_SESSION[$k];
                    $rc->session->remove($k);
                }
            }

            $_SESSION['imap_delimiter'] = $_SESSION['imap_delimiter' . ident_switch::MY_POSTFIX] ?? null;
            $_SESSION['username'] = $rc->user->data['username'];
            $_SESSION['password'] = $_SESSION['password' . ident_switch::MY_POSTFIX];
            $_SESSION['iid' . ident_switch::MY_POSTFIX] = -1;

            foreach (rcube_storage::$folder_types as $type) {
                $otherKey = $type . '_mbox' . ident_switch::MY_POSTFIX;
                if (isset($_SESSION[$otherKey])) {
                    $rc->session->remove($otherKey);
                }
            }
        } else {
            $sql = 'SELECT imap_host, flags, imap_port, imap_delimiter, drafts_mbox, sent_mbox,'
                . ' junk_mbox, trash_mbox, archive_mbox, ' . IdentSwitchCredentialService::ACCOUNT_FIELDS
                . ' FROM ' . $rc->db->table_name(ident_switch::TABLE)
                . ' WHERE id = ? AND user_id = ? AND flags & ? > 0';
            $q = $rc->db->query($sql, $identId, $rc->user->ID, ident_switch::DB_ENABLED);
            $r = $rc->db->fetch_assoc($q);
            if (is_array($r)) {
                $r['username'] = ident_switch::resolve_username((int)$r['iid'], $r['username']);
                $credentials = $this->resolveCredentials(
                    $r,
                    \SizeStation\Roundcube\Credentials\CredentialPurpose::Imap,
                );
                if ($credentials === null) {
                    return false;
                }

                $managed = $this->credentials->isManaged($r);
                if ($managed) {
                    $r['imap_host'] = $rc->config->get('ident_switch.managed_imap_host');
                    $r['imap_port'] = $rc->config->get('ident_switch.managed_imap_port');
                    if (!$this->validManagedEndpoint($r['imap_host'], $r['imap_port'])) {
                        $this->managedEndpointError((int) $r['iid'], 'IMAP');

                        return false;
                    }
                }

                $parsed = ident_switch::parse_host_scheme($r['imap_host'] ?: 'localhost');
                $host = $parsed['host'];
                $ssl = $parsed['scheme'] ?: null;

                if (!$ssl && ($r['flags'] & ident_switch::DB_SECURE_IMAP_TLS)) {
                    $ssl = 'tls'; // Backward compat: old records without scheme in host
                }

                $def_port = ($ssl === 'ssl') ? 993 : 143;
                $port = $r['imap_port'] ?: $def_port;

                $delimiter = $r['imap_delimiter'] ?: null;

                if ($managed && !$this->validateTargetConnection($r, $credentials, $host, $ssl, (int) $port)) {
                    return false;
                }

                ident_switch::write_log("Switching mailbox to one for identity with ID = {$r['iid']} (username = '{$r['username']}').");

                // Validation is complete. Only now mutate the active mailbox session.
                $rc->session->remove('folders');
                $rc->session->remove('unseen_count');
                $this->reset_baseline(null, $rc, $identId);

                if ($_SESSION['username'] === $rc->user->data['username']) {
                    // If we are in default account now - save values
                    foreach ($_SESSION as $k => $v) {
                        if (str_starts_with(strtolower($k), 'storage') && !str_ends_with($k, ident_switch::MY_POSTFIX)) {
                            if (!isset($_SESSION[$k . ident_switch::MY_POSTFIX])) {
                                $_SESSION[$k . ident_switch::MY_POSTFIX] = $_SESSION[$k];
                            }
                            $rc->session->remove($k);
                        }
                    }

                    foreach (['password', 'imap_delimiter'] as $k) {
                        if (!isset($_SESSION[$k . ident_switch::MY_POSTFIX])) {
                            $_SESSION[$k . ident_switch::MY_POSTFIX] = $_SESSION[$k];
                        }
                        $rc->session->remove($k);
                    }
                }

                $_SESSION['storage_host'] = $host;
                $_SESSION['storage_ssl'] = $ssl;
                $_SESSION['storage_port'] = $port;
                $_SESSION['imap_delimiter'] = $delimiter;
                $_SESSION['username'] = $credentials->imapUsername();
                $_SESSION['password'] = $rc->encrypt($credentials->imapPassword());
                $_SESSION['iid' . ident_switch::MY_POSTFIX] = $r['iid'];

                foreach (rcube_storage::$folder_types as $type) {
                    if (!empty($r[$type . '_mbox'])) {
                        $otherKey = $type . '_mbox' . ident_switch::MY_POSTFIX;
                        $_SESSION[$otherKey] = $r[$type . '_mbox'];
                    }
                }
            } else {
                ident_switch::write_log("Requested remote mailbox with ID = {$identId} not found.");
                return false;
            }
        }

        if ($redirect) {
            $rc->output->redirect([
                '_task' => 'mail',
                '_mbox' => 'INBOX',
            ]);
        }

        return true;
    }

    /**
     * Handle smtp_connect hook: configure SMTP settings for the active account.
     *
     * Loads SMTP host, port, credentials, and TLS settings from the database
     * for the currently selected identity.
     *
     * @param array $args Hook arguments containing SMTP connection parameters.
     * @return array Modified hook arguments with updated SMTP settings.
     */
    public function configure_smtp(array $args): array
    {
        $iid = $_SESSION['iid' . ident_switch::MY_POSTFIX] ?? null;
        if (!is_numeric($iid) || (int)$iid === -1) {
            ident_switch::debug_log('SMTP: no active switch, resolving from _from header');
            $requestFrom = rcube_utils::get_input_value('_from', rcube_utils::INPUT_POST);
            if (empty($requestFrom)) {
                ident_switch::debug_log('SMTP: no _from parameter, using default config');
                return $args;
            }

            $iid = intval($requestFrom);
            if ($iid === 0) {
                ident_switch::debug_log('SMTP: _from is not an integer, using default config');
                return $args;
            }
        }

        $rc = rcmail::get_instance();

        $sql = 'SELECT smtp_host, smtp_port, smtp_auth, ' . IdentSwitchCredentialService::ACCOUNT_FIELDS
            . ' FROM ' . $rc->db->table_name(ident_switch::TABLE)
            . ' WHERE iid = ? AND user_id = ? AND flags & ? > 0';
        $q = $rc->db->query($sql, $iid, $rc->user->ID, ident_switch::DB_ENABLED);
        $r = $rc->db->fetch_assoc($q);
        if (is_array($r)) {
            // If this is an alias, follow parent_id to get the parent's SMTP config
            if (!empty($r['parent_id'])) {
                ident_switch::debug_log("SMTP: identity {$iid} is alias, following parent_id={$r['parent_id']}");
                $sql = 'SELECT smtp_host, smtp_port, smtp_auth, '
                    . IdentSwitchCredentialService::ACCOUNT_FIELDS . ' FROM '
                    . $rc->db->table_name(ident_switch::TABLE)
                    . ' WHERE id = ? AND user_id = ? AND flags & ? > 0';
                $q = $rc->db->query($sql, $r['parent_id'], $rc->user->ID, ident_switch::DB_ENABLED);
                $r = $rc->db->fetch_assoc($q);
                if (!is_array($r)) {
                    ident_switch::debug_log("SMTP: parent account not found, using default config");
                    return $args;
                }
                $iid = $r['iid'];
            }

            $authMode = (int)$r['smtp_auth'];
            $credentials = null;
            if ($authMode !== ident_switch::SMTP_AUTH_NONE) {
                $r['username'] = ident_switch::resolve_username($iid, $r['username']);
                $credentials = $this->resolveCredentials(
                    $r,
                    \SizeStation\Roundcube\Credentials\CredentialPurpose::Smtp,
                );
                if ($credentials === null) {
                    return $args;
                }
            }
            if ($authMode === ident_switch::SMTP_AUTH_CUSTOM) {
                $args['smtp_user'] = $credentials->smtpUsername();
                $args['smtp_pass'] = $credentials->smtpPassword();
            } elseif ($authMode === ident_switch::SMTP_AUTH_IMAP) {
                $args['smtp_user'] = $credentials->imapUsername();
                $args['smtp_pass'] = $credentials->imapPassword();
            } else {
                $args['smtp_user'] = '';
                $args['smtp_pass'] = '';
            }

            // Host already contains scheme (ssl:// or tls://) from form
            $smtpHost = $this->credentials->isManaged($r)
                ? $rc->config->get('ident_switch.managed_smtp_host')
                : $r['smtp_host'];
            $smtpPort = $this->credentials->isManaged($r)
                ? $rc->config->get('ident_switch.managed_smtp_port')
                : $r['smtp_port'];
            $smtpHost = $smtpHost ?: 'localhost';
            $smtpPort = $smtpPort ?: 587;
            if ($this->credentials->isManaged($r) && !$this->validManagedEndpoint($smtpHost, $smtpPort)) {
                $this->managedEndpointError((int) $r['iid'], 'SMTP');

                return $args;
            }
            $args['smtp_host'] = $smtpHost . ':' . $smtpPort;

            $authLabel = match ($authMode) {
                ident_switch::SMTP_AUTH_IMAP => 'imap',
                ident_switch::SMTP_AUTH_NONE => 'none',
                ident_switch::SMTP_AUTH_CUSTOM => 'custom',
                default => "unknown({$authMode})",
            };
            ident_switch::debug_log("SMTP: iid={$iid}, host={$args['smtp_host']}, user={$args['smtp_user']}, auth={$authLabel}");
        }

        return $args;
    }

    /**
     * Handle managesieve_connect hook: configure Sieve settings for the active account.
     *
     * Loads Sieve host, port, and credentials from the database
     * for the currently selected identity.
     *
     * @param array $args Hook arguments containing Sieve connection parameters.
     * @return array Modified hook arguments with updated Sieve settings.
     */
    public function configure_managesieve(array $args): array
    {
        $iid = $_SESSION['iid' . ident_switch::MY_POSTFIX] ?? null;
        if (!is_numeric($iid) || (int)$iid === -1) {
            return $args;
        }

        $rc = rcmail::get_instance();

        $sql = 'SELECT sieve_host, sieve_port, sieve_auth, ' . IdentSwitchCredentialService::ACCOUNT_FIELDS
            . ' FROM ' . $rc->db->table_name(ident_switch::TABLE)
            . ' WHERE iid = ? AND user_id = ? AND flags & ? > 0';
        $q = $rc->db->query($sql, $iid, $rc->user->ID, ident_switch::DB_ENABLED);
        $r = $rc->db->fetch_assoc($q);
        if (is_array($r)) {
            // If this is an alias, follow parent_id to get the parent's Sieve config
            if (!empty($r['parent_id'])) {
                ident_switch::debug_log("Sieve: identity {$iid} is alias, following parent_id={$r['parent_id']}");
                $sql = 'SELECT sieve_host, sieve_port, sieve_auth, '
                    . IdentSwitchCredentialService::ACCOUNT_FIELDS . ' FROM '
                    . $rc->db->table_name(ident_switch::TABLE)
                    . ' WHERE id = ? AND user_id = ? AND flags & ? > 0';
                $q = $rc->db->query($sql, $r['parent_id'], $rc->user->ID, ident_switch::DB_ENABLED);
                $r = $rc->db->fetch_assoc($q);
                if (!is_array($r)) {
                    ident_switch::debug_log("Sieve: parent account not found, using default config");
                    return $args;
                }
                $iid = $r['iid'];
            }

            $sieveHost = $this->credentials->isManaged($r)
                ? $rc->config->get('ident_switch.managed_sieve_host')
                : $r['sieve_host'];
            if (empty($sieveHost)) {
                if ($this->credentials->isManaged($r)) {
                    $this->managedEndpointError((int) $r['iid'], 'Sieve');
                }

                return $args;
            }

            $sievePort = $this->credentials->isManaged($r)
                ? $rc->config->get('ident_switch.managed_sieve_port')
                : $r['sieve_port'];
            $sievePort = $sievePort ?: 4190;
            if ($this->credentials->isManaged($r) && !$this->validManagedEndpoint($sieveHost, $sievePort)) {
                $this->managedEndpointError((int) $r['iid'], 'Sieve');

                return $args;
            }

            $authMode = (int)$r['sieve_auth'];
            $credentials = null;
            if ($authMode !== ident_switch::SIEVE_AUTH_NONE) {
                $r['username'] = ident_switch::resolve_username($iid, $r['username']);
                $credentials = $this->resolveCredentials(
                    $r,
                    \SizeStation\Roundcube\Credentials\CredentialPurpose::Sieve,
                );
                if ($credentials === null) {
                    return $args;
                }
            }
            if ($authMode === ident_switch::SIEVE_AUTH_CUSTOM) {
                $args['user'] = $credentials->sieveUsername();
                $args['password'] = $credentials->sievePassword();
            } elseif ($authMode === ident_switch::SIEVE_AUTH_IMAP) {
                $args['user'] = $credentials->imapUsername();
                $args['password'] = $credentials->imapPassword();
            } else {
                $args['user'] = '';
                $args['password'] = '';
            }
            $args['host'] = $sieveHost . ':' . $sievePort;

            ident_switch::debug_log("Sieve: iid={$iid}, host={$args['host']}, user={$args['user']}");
        }

        return $args;
    }

    /**
     * Handle preferences_list hook: customize special folders form for remote accounts.
     *
     * When viewing folder preferences while impersonating, shows the remote account's
     * special folder assignments instead of the default ones.
     *
     * @param array $args Hook arguments containing 'section' and 'blocks' with form data.
     * @return array Modified hook arguments with updated folder selections.
     */
    public function get_special_folders_form(array $args): array
    {
        $rc = rcmail::get_instance();

        if (
            $args['section'] === 'folders'
            && strcasecmp($rc->user->data['username'], $_SESSION['username']) !== 0
        ) {
            $no_override = array_flip((array)$rc->config->get('dont_override'));
            $onchange = "if ($(this).val() == 'INBOX') $(this).val('')";
            $select = $rc->folder_selector([
                'noselection' => '---',
                'realnames' => true,
                'maxlength' => 30,
                'folder_filter' => 'mail',
                'folder_rights' => 'w',
            ]);

            $sql = 'SELECT label FROM ' . $rc->db->table_name(ident_switch::TABLE) . ' WHERE iid = ? AND user_id = ?';
            $q = $rc->db->query($sql, $_SESSION['iid' . ident_switch::MY_POSTFIX], $rc->user->ID);
            $r = $rc->db->fetch_assoc($q);
            $args['blocks']['main']['name'] .= ' (' . ($r['label'] ? rcube::Q($rc->gettext('server')) . ': ' . rcube::Q($r['label']) : 'remote') . ')';

            foreach (rcube_storage::$folder_types as $type) {
                if (isset($no_override[$type . '_mbox'])) {
                    continue;
                }

                $defaultKey = $type . '_mbox_default' . ident_switch::MY_POSTFIX;
                $otherKey = $type . '_mbox' . ident_switch::MY_POSTFIX;
                $selected = $_SESSION[$otherKey] ?? $_SESSION[$defaultKey] ?? '';
                $attr = ['id' => '_' . $type . '_mbox', 'name' => '_' . $type . '_mbox', 'onchange' => $onchange];
                $args['blocks']['main']['options'][$type . '_mbox']['content'] = $select->show($selected, $attr);
            }
        }

        return $args;
    }

    /**
     * Handle preferences_save hook: persist special folder assignments for remote accounts.
     *
     * Saves folder preferences to the plugin's database table instead of the default
     * Roundcube preferences when impersonating a remote account.
     *
     * @param array $args Hook arguments containing 'section' and 'prefs' with folder data.
     * @return array Modified hook arguments, with 'abort' set to prevent default save.
     */
    public function save_special_folders(array $args): array
    {
        $rc = rcmail::get_instance();

        if (
            $args['section'] === 'folders'
            && strcasecmp($rc->user->data['username'], $_SESSION['username']) !== 0
        ) {
            $sql = 'SELECT id FROM ' . $rc->db->table_name(ident_switch::TABLE) . ' WHERE iid = ? AND user_id = ?';
            $q = $rc->db->query($sql, $_SESSION['iid' . ident_switch::MY_POSTFIX], $rc->user->ID);
            $r = $rc->db->fetch_assoc($q);
            if ($r) {
                $sql = 'UPDATE ' .
                    $rc->db->table_name(ident_switch::TABLE) .
                    ' SET drafts_mbox = ?, sent_mbox = ?, junk_mbox = ?, trash_mbox = ?, archive_mbox = ?' .
                    ' WHERE id = ?';

                $rc->db->query(
                    $sql,
                    $args['prefs']['drafts_mbox'],
                    $args['prefs']['sent_mbox'],
                    $args['prefs']['junk_mbox'],
                    $args['prefs']['trash_mbox'],
                    $args['prefs']['archive_mbox'] ?? null,
                    $r['id']
                );

                // Abort to prevent RC from saving prefs to default storage
                $args['abort'] = true;
                $args['result'] = true;

                foreach (rcube_storage::$folder_types as $type) {
                    if (!empty($args['prefs'][$type . '_mbox'])) {
                        $otherKey = $type . '_mbox' . ident_switch::MY_POSTFIX;
                        $_SESSION[$otherKey] = $args['prefs'][$type . '_mbox'];
                    }
                }
                return $args;
            }

            $args['abort'] = true;
            $args['result'] = false;
            return $args;
        }

        foreach (rcube_storage::$folder_types as $type) {
            if (!empty($args['prefs'][$type . '_mbox'])) {
                $key = $type . '_mbox_default' . ident_switch::MY_POSTFIX;
                $_SESSION[$key] = $args['prefs'][$type . '_mbox'];
            }
        }
        return $args;
    }

    /**
     * Reset the baseline for a target account so delta display resets to 0.
     *
     * For primary account (identId=-1), iid is 0.
     * For secondary accounts, look up iid from the ident_switch table.
     *
     * @param integer|null $iid     Known iid (0 for primary), or null to look up.
     * @param rcmail       $rc      Roundcube instance.
     * @param mixed        $identId The ident_switch.id value for secondary accounts.
     */
    private function reset_baseline(?int $iid, rcmail $rc, mixed $identId): void
    {
        if ($iid === null) {
            // Look up iid from ident_switch table for secondary account
            $sql = 'SELECT iid FROM ' . $rc->db->table_name(ident_switch::TABLE) . ' WHERE id = ? AND user_id = ?';
            $q = $rc->db->query($sql, $identId, $rc->user->ID);
            $r = $rc->db->fetch_assoc($q);
            if (!$r) {
                return;
            }
            $iid = (int)$r['iid'];
        }

        $counts = $_SESSION['ident_switch_counts'] ?? [];
        if (isset($counts[$iid])) {
            unset($counts[$iid]['baseline']);
            $_SESSION['ident_switch_counts'] = $counts;
        }
    }

    /**
     * @param array<string, mixed> $account
     */
    private function resolveCredentials(
        array $account,
        \SizeStation\Roundcube\Credentials\CredentialPurpose $purpose,
    ): ?\SizeStation\Roundcube\Credentials\AccountCredentials
    {
        try {
            return $this->credentials->resolve($account, $purpose);
        } catch (\SizeStation\Roundcube\Credentials\Exception\CredentialException $exception) {
            ident_switch::write_log(
                "Credential resolution failed for identity {$account['iid']}: {$exception->errorCode}",
            );
            rcmail::get_instance()->output->show_message('ident_switch.err.credential', 'error');

            return null;
        }
    }

    private function validManagedEndpoint(mixed $host, mixed $port): bool
    {
        return is_string($host)
            && preg_match('/\A(?:ssl|tls):\/\/[A-Za-z0-9.-]+\z/', $host) === 1
            && is_numeric($port)
            && (int) $port > 0
            && (int) $port <= 65535;
    }

    /** @param array<string, mixed> $account */
    private function validateTargetConnection(
        array $account,
        \SizeStation\Roundcube\Credentials\AccountCredentials $credentials,
        string $host,
        ?string $ssl,
        int $port,
    ): bool {
        $imap = new rcube_imap_generic();
        $connected = $imap->connect($host, $credentials->imapUsername(), $credentials->imapPassword(), [
            'port' => $port,
            'ssl_mode' => $ssl,
            'timeout' => 5,
        ]);
        if (!$connected) {
            ident_switch::write_log("IMAP connection validation failed for identity {$account['iid']}.");
            rcmail::get_instance()->output->show_message('ident_switch.err.credential', 'error');

            return false;
        }

        $imap->closeConnection();

        return true;
    }

    private function managedEndpointError(int $identityId, string $protocol): void
    {
        ident_switch::write_log("Managed {$protocol} endpoint is invalid for identity {$identityId}.");
        rcmail::get_instance()->output->show_message('ident_switch.err.credential', 'error');
    }
}
