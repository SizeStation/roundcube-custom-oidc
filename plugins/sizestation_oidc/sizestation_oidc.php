<?php

/**
 * Authentik OIDC and OpenBao-managed mailbox assignments for Roundcube.
 *
 * Copyright (C) 2026 SizeStation
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
class roundcube_oidc_suite extends ident_switch
{
    public $task = 'login|logout|mail|settings';

    private ?\SizeStation\Roundcube\Oidc\Service\LoginPhase $loginPhase = null;
    private ?string $anchorFailureStatus = null;
    private ?string $logoutRedirect = null;
    /** @var array<string, mixed>|null */
    private ?array $logoutIdentity = null;

    public function init(): void
    {
        $this->loadSizeStationAutoloader();
        $this->load_config('config.runtime.php');
        $this->load_config();
        if (!$this->enabled()) {
            return;
        }

        parent::init();
        $this->add_texts('plugins/sizestation_oidc/localization/');
        $this->add_hook('startup', [$this, 'onStartup']);
        $this->add_hook('loginform_content', [$this, 'onLoginForm']);
        $this->add_hook('authenticate', [$this, 'onAuthenticate']);
        $this->add_hook('login_after', [$this, 'onLoginAfter']);
        $this->add_hook('login_failed', [$this, 'onLoginFailed']);
        $this->add_hook('logout_after', [$this, 'onLogoutAfter']);
        $this->register_action('plugin.sizestation_oidc.login', [$this, 'noopAction']);
        $this->register_action('plugin.sizestation_oidc.callback', [$this, 'noopAction']);
        $this->register_action('plugin.sizestation_oidc.select-account', [$this, 'selectAccountAction']);
    }

    /** @param array<string, mixed> $args
     *  @return array<string, mixed>
     */
    public function onStartup(array $args): array
    {
        $task = (string) ($args['task'] ?? '');
        $action = (string) ($args['action'] ?? '');
        if ($task === 'logout') {
            $this->prepareLogout();

            return $args;
        }
        if ($task !== 'login' && !$this->validateEstablishedSession()) {
            return ['task' => 'login', 'action' => ''];
        }
        if ($task !== 'login' && $this->returnFromDisabledManagedAssignment()) {
            return $args;
        }
        if ($task === 'mail') {
            $this->performPendingPreferredSwitch();
            $this->presentPendingAccountSelection();

            return $args;
        }
        if ($task !== 'login') {
            return $args;
        }

        if ($action === 'plugin.sizestation_oidc.login') {
            try {
                rcmail::get_instance()->output->redirect($this->flow()->authorizationUrl($_SESSION));
            } catch (\Throwable $exception) {
                $this->failSafely('oidc_start_failed', $exception);
            }

            return ['task' => 'login', 'action' => ''];
        }
        if ($action !== 'plugin.sizestation_oidc.callback') {
            return $args;
        }

        try {
            $state = rcube_utils::get_input_string('state', rcube_utils::INPUT_GET);
            $error = rcube_utils::get_input_string('error', rcube_utils::INPUT_GET);
            if ($error !== '') {
                $this->flow()->rejectProviderError($_SESSION, $state, $this->sourceKey());
                throw new \RuntimeException('OIDC provider rejected authorization');
            }
            $code = rcube_utils::get_input_string('code', rcube_utils::INPUT_GET);
            $identity = $this->flow()->complete($_SESSION, $state, $code, $this->sourceKey());
            $this->loginPhase = $this->bootstrap()->prepare(
                $identity,
                $this->sourceIp(),
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            );

            return ['task' => 'login', 'action' => 'login'];
        } catch (\SizeStation\Roundcube\Oidc\Domain\NoMailboxAssignedException $exception) {
            $this->failSafely('no_mailbox_assigned', $exception, false);
            rcmail::get_instance()->output->show_message($this->gettext('nomailboxassigned'), 'error');

            return ['task' => 'login', 'action' => ''];
        } catch (\Throwable $exception) {
            $this->failSafely('oidc_callback_failed', $exception);

            return ['task' => 'login', 'action' => ''];
        }
    }

    /** @param array<string, mixed> $form
     *  @return array<string, mixed>
     */
    public function onLoginForm(array $form): array
    {
        $rc = rcmail::get_instance();
        if ($rc->config->get('sizestation_oidc.hide_password_form', true)) {
            $form['hidden'] = [];
            $form['inputs'] = [];
            $form['buttons'] = [];
        }
        $button = html::a([
            'href' => $rc->url(['_task' => 'login', '_action' => 'plugin.sizestation_oidc.login']),
            'id' => 'sizestation-oidc-login',
            'class' => 'button mainaction',
        ], rcube::Q($this->gettext('loginwithauthentik')));
        $form['buttons']['sizestation_oidc'] = ['outterclass' => 'sizestation-oidc-login', 'content' => $button];
        $sourceUrl = (string) $rc->config->get('sizestation_oidc.source_url', '');
        if (filter_var($sourceUrl, FILTER_VALIDATE_URL) && str_starts_with($sourceUrl, 'https://')) {
            $form['buttons']['sizestation_oidc_source'] = [
                'outterclass' => 'sizestation-oidc-source',
                'content' => html::a([
                    'href' => $sourceUrl,
                    'rel' => 'noopener noreferrer',
                    'target' => '_blank',
                ], rcube::Q($this->gettext('sourcecode'))),
            ];
        }

        return $form;
    }

    /** @param array<string, mixed> $args
     *  @return array<string, mixed>
     */
    public function onAuthenticate(array $args): array
    {
        if ($this->loginPhase === null) {
            return $args;
        }
        try {
            $principalId = (int) $this->loginPhase->principal['id'];
            $assignmentId = (string) $this->loginPhase->anchor['id'];
            $assignment = $this->assignments()->findOwnedEnabledAnchor($assignmentId, $principalId);
            if ($assignment === null) {
                throw new \RuntimeException('Pending anchor assignment is no longer valid');
            }
            $credentials = $this->anchorCredentials()->resolve($assignment);
            $password = $credentials->imapPassword();
            $host = $this->requiredConfig('sizestation_oidc.imap_host');
            $this->runtimeIdentityGuard()->assertAnchorMapping(
                $this->loginPhase->principal,
                (string) $assignment['mailbox_address'],
                $credentials->imapUsername(),
                $host,
            );
            $args['host'] = $host;
            $args['user'] = $credentials->imapUsername();
            $args['pass'] = $password;
            $args['cookiecheck'] = false;
            $args['valid'] = true;
            $args['sso'] = true;
            rcmail::get_instance()->config->set('login_password_maxlen', strlen($password));
            unset($password, $credentials);
        } catch (\Throwable $exception) {
            $args['valid'] = false;
            $args['abort'] = true;
            $status = 'unavailable';
            $errorCode = 'anchor_credentials_unavailable';
            if ($exception instanceof \SizeStation\Roundcube\Credentials\Exception\ExternalCredentialException) {
                $errorCode = $exception->errorCode;
                if ($exception->kind === \SizeStation\Roundcube\Credentials\Exception\CredentialFailureKind::Invalid) {
                    $status = 'invalid';
                }
            }
            $this->anchorFailureStatus = $status;
            try {
                if ($status === 'unavailable') {
                    $this->assignments()->recordCredentialAvailabilityFailure(
                        (string) $this->loginPhase->anchor['id'],
                        (int) $this->loginPhase->principal['id'],
                        $errorCode,
                    );
                } else {
                    $this->assignments()->markCredentialFailure(
                        (string) $this->loginPhase->anchor['id'],
                        (int) $this->loginPhase->principal['id'],
                        $status,
                        $errorCode,
                    );
                }
                $this->audit()->record(
                    $status === 'unavailable'
                        ? \SizeStation\Roundcube\Oidc\Audit\AuditEvent::OpenBaoUnavailable
                        : \SizeStation\Roundcube\Oidc\Audit\AuditEvent::CredentialValidationFailure,
                    'system',
                    'anchor',
                    (int) $this->loginPhase->principal['id'],
                    (string) $this->loginPhase->anchor['id'],
                    ['error_code' => $errorCode],
                );
            } catch (\Throwable) {
            }
            $this->failSafely('anchor_credentials_unavailable', $exception, false);
        }

        return $args;
    }

    /** @param array<string, mixed> $args
     *  @return array<string, mixed>
     */
    public function onLoginAfter(array $args): array
    {
        if ($this->loginPhase === null) {
            return $args;
        }
        $rc = rcmail::get_instance();
        $principalId = (int) $this->loginPhase->principal['id'];
        $assignmentId = (string) $this->loginPhase->anchor['id'];
        try {
            try {
                $this->audit()->record(
                    \SizeStation\Roundcube\Oidc\Audit\AuditEvent::ReconciliationStarted,
                    'system',
                    'login',
                    $principalId,
                );
            } catch (\Throwable) {
            }
            $result = (new \SizeStation\Roundcube\Oidc\Service\LoginFinalizer($rc->db))->finalize(
                $principalId,
                (int) $rc->user->ID,
                $assignmentId,
                $this->loginPhase->assignments,
            );
            $this->recordReconciliationCompleted($principalId, $result, $this->loginPhase->assignments);
            (new \SizeStation\Roundcube\Oidc\Session\OidcSession())->establish($_SESSION, $this->loginPhase);
            try {
                $this->audit()->record(
                    \SizeStation\Roundcube\Oidc\Audit\AuditEvent::OidcLoginSuccess,
                    'oidc',
                    $this->loginPhase->identity->subject,
                    $principalId,
                    $assignmentId,
                    ['roundcube_user_id' => (int) $rc->user->ID],
                    $this->sourceIp(),
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                );
            } catch (\Throwable) {
            }
            $this->loginPhase = null;

            return ['_task' => 'mail', '_mbox' => 'INBOX'];
        } catch (\SizeStation\Roundcube\Oidc\Reconciliation\RecoverableMaterializationException $exception) {
            (new \SizeStation\Roundcube\Oidc\Session\OidcSession())->establish($_SESSION, $this->loginPhase);
            unset($_SESSION['sizestation_oidc.preferred_switch_id']);
            try {
                $this->audit()->record(
                    \SizeStation\Roundcube\Oidc\Audit\AuditEvent::ReconciliationFailed,
                    'system',
                    'login',
                    $principalId,
                    $assignmentId,
                    ['error_code' => 'secondary_materialization_failed', 'anchor_login_preserved' => true],
                );
                $this->audit()->record(
                    \SizeStation\Roundcube\Oidc\Audit\AuditEvent::OidcLoginSuccess,
                    'oidc',
                    $this->loginPhase->identity->subject,
                    $principalId,
                    $assignmentId,
                    ['roundcube_user_id' => (int) $rc->user->ID, 'degraded' => true],
                    $this->sourceIp(),
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                );
            } catch (\Throwable) {
            }
            $rc->write_log(
                'sizestation_oidc',
                'event=materialization_failure code=secondary_materialization_failed anchor_login_preserved=true',
            );
            $rc->output->show_message($this->gettext('secondaryunavailable'), 'warning');
            $this->loginPhase = null;

            return ['_task' => 'mail', '_mbox' => 'INBOX'];
        } catch (\Throwable $exception) {
            (new \SizeStation\Roundcube\Oidc\Session\OidcSession())->clear($_SESSION);
            unset($_SESSION['sizestation_oidc.preferred_switch_id']);
            try {
                $this->audit()->record(
                    \SizeStation\Roundcube\Oidc\Audit\AuditEvent::ReconciliationFailed,
                    'system',
                    'login',
                    $principalId,
                    $assignmentId,
                    ['error_code' => 'login_finalization_rolled_back'],
                );
            } catch (\Throwable) {
            }
            $this->failSafely('oidc_login_finalize_failed', $exception, false);
            $this->loginPhase = null;
            $rc->kill_session();

            return ['_task' => 'login'];
        }
    }

    /** @param array<string, mixed> $args
     *  @return array<string, mixed>
     */
    public function onLoginFailed(array $args): array
    {
        if ($this->loginPhase !== null) {
            try {
                if ($this->anchorFailureStatus === null) {
                    $this->assignments()->markCredentialFailure(
                        (string) $this->loginPhase->anchor['id'],
                        (int) $this->loginPhase->principal['id'],
                        'invalid',
                        'anchor_imap_authentication_failed',
                    );
                }
                $this->audit()->record(
                    \SizeStation\Roundcube\Oidc\Audit\AuditEvent::OidcLoginFailure,
                    'oidc',
                    $this->loginPhase->identity->subject,
                    (int) $this->loginPhase->principal['id'],
                    (string) $this->loginPhase->anchor['id'],
                    ['error_code' => 'anchor_imap_authentication_failed'],
                    $this->sourceIp(),
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                );
            } catch (\Throwable) {
                // The primary failure must remain sanitized and visible even if auditing is unavailable.
            }
        }
        $this->anchorFailureStatus = null;
        $this->loginPhase = null;

        return $args;
    }

    /** @param array<string, mixed> $args
     *  @return array<string, mixed>
     */
    public function onLogoutAfter(array $args): array
    {
        if ($this->logoutIdentity !== null) {
            try {
                $this->audit()->record(
                    \SizeStation\Roundcube\Oidc\Audit\AuditEvent::CompleteLogout,
                    'oidc',
                    (string) ($this->logoutIdentity['subject'] ?? 'unknown'),
                    (int) ($this->logoutIdentity['principal_id'] ?? 0) ?: null,
                    metadata: ['roundcube_user' => $args['user'] ?? null],
                    sourceIp: $this->sourceIp(),
                    userAgent: $_SERVER['HTTP_USER_AGENT'] ?? null,
                );
            } catch (\Throwable) {
            }
        }
        if ($this->logoutRedirect !== null) {
            rcmail::get_instance()->output->redirect($this->logoutRedirect);
        }

        return $args;
    }

    public function noopAction(): void
    {
    }

    public function selectAccountAction(): void
    {
        $rc = rcmail::get_instance();
        $oidcIdentity = (new \SizeStation\Roundcube\Oidc\Session\OidcSession())->identity($_SESSION);
        $rawId = rcube_utils::get_input_value('_ident-id', rcube_utils::INPUT_POST);
        if (
            $oidcIdentity === null
            || empty($_SESSION['sizestation_oidc.account_selection_pending'])
            || !is_string($rawId)
            || !preg_match('/\A(?:-1|[1-9][0-9]*)\z/', $rawId)
        ) {
            $rc->output->show_message($this->gettext('secondaryunavailable'), 'error');
            return;
        }
        $recordId = (int) $rawId;
        if ($recordId === -1) {
            unset($_SESSION['sizestation_oidc.account_selection_pending']);
            $rc->output->redirect(['_task' => 'mail', '_mbox' => 'INBOX']);
            return;
        }
        try {
            if (!$this->selectedManagedRecordAllowed($recordId, (int) $oidcIdentity['principal_id'])) {
                throw new \RuntimeException('The selected mailbox is not assigned to this principal');
            }
            if (!class_exists('IdentSwitchSwitcher')) {
                throw new \RuntimeException('The account switcher is unavailable');
            }
            $switcher = new IdentSwitchSwitcher(new IdentSwitchCredentialService($rc));
            if (!$switcher->switchAccountById($recordId, false)) {
                throw new \RuntimeException('The selected mailbox is unavailable');
            }
            unset($_SESSION['sizestation_oidc.account_selection_pending']);
            try {
                $this->audit()->record(
                    \SizeStation\Roundcube\Oidc\Audit\AuditEvent::MailboxSwitch,
                    'user',
                    'account_selector',
                    (int) $oidcIdentity['principal_id'],
                    metadata: ['ident_switch_record_id' => $recordId],
                    sourceIp: $this->sourceIp(),
                );
            } catch (\Throwable) {
            }
            $rc->output->redirect(['_task' => 'mail', '_mbox' => 'INBOX']);
        } catch (\Throwable $exception) {
            $this->failSafely('account_selection_failed', $exception, false);
            $rc->output->show_message($this->gettext('secondaryunavailable'), 'error');
        }
    }

    private function prepareLogout(): void
    {
        $session = new \SizeStation\Roundcube\Oidc\Session\OidcSession();
        $this->logoutIdentity = $session->identity($_SESSION);
        if ($this->logoutIdentity === null) {
            return;
        }
        try {
            $this->logoutRedirect = $this->flow()->endSessionUrl(
                $this->requiredConfig('sizestation_oidc.post_logout_redirect_uri'),
            );
        } catch (\Throwable $exception) {
            $this->failSafely('oidc_logout_discovery_failed', $exception, false);
        }
        $session->clear($_SESSION);
    }

    private function recordReconciliationCompleted(
        int $principalId,
        \SizeStation\Roundcube\Oidc\Reconciliation\ReconciliationResult $result,
        array $assignments,
    ): void {
        try {
            $this->audit()->record(
                \SizeStation\Roundcube\Oidc\Audit\AuditEvent::ReconciliationCompleted,
                'system',
                'login',
                $principalId,
                metadata: [
                    'created' => $result->created,
                    'updated' => $result->updated,
                    'disabled' => $result->disabled,
                    'orphaned' => $result->orphaned,
                ],
            );
        } catch (\Throwable) {
        }
        foreach ($result->materialized as $materialized) {
            try {
                $this->audit()->record(
                    \SizeStation\Roundcube\Oidc\Audit\AuditEvent::AssignmentMaterialized,
                    'system',
                    'login',
                    $principalId,
                    $materialized['assignment_id'],
                    [
                        'ident_switch_record_id' => $materialized['record_id'],
                        'roundcube_identity_id' => $materialized['identity_id'],
                    ],
                );
            } catch (\Throwable) {
            }
        }
        if ($result->preferredSwitchRecordId !== null) {
            $_SESSION['sizestation_oidc.preferred_switch_id'] = $result->preferredSwitchRecordId;
            unset($_SESSION['sizestation_oidc.account_selection_pending']);
        } elseif ($result->materialized !== [] && !$this->hasEnabledPreference($assignments)) {
            $_SESSION['sizestation_oidc.account_selection_pending'] = true;
        } else {
            unset($_SESSION['sizestation_oidc.account_selection_pending']);
        }
    }

    /** @param list<array<string, mixed>> $assignments */
    private function hasEnabledPreference(array $assignments): bool
    {
        foreach ($assignments as $assignment) {
            if (!empty($assignment['enabled']) && !empty($assignment['is_preferred'])) {
                return true;
            }
        }

        return false;
    }

    private function validateEstablishedSession(): bool
    {
        $session = new \SizeStation\Roundcube\Oidc\Session\OidcSession();
        $identity = $session->identity($_SESSION);
        if ($identity === null) {
            return true;
        }

        $rc = rcmail::get_instance();
        try {
            $this->runtimeIdentityGuard()->assertEstablishedSession($identity, (int) $rc->user->ID);

            return true;
        } catch (\Throwable $exception) {
            $session->clear($_SESSION);
            unset($_SESSION['sizestation_oidc.preferred_switch_id']);
            $this->failSafely('oidc_session_invalid', $exception);
            $rc->kill_session();

            return false;
        }
    }

    private function performPendingPreferredSwitch(): void
    {
        $recordId = $_SESSION['sizestation_oidc.preferred_switch_id'] ?? null;
        if (!is_int($recordId) && !(is_string($recordId) && ctype_digit($recordId))) {
            return;
        }
        unset($_SESSION['sizestation_oidc.preferred_switch_id']);
        $oidcIdentity = (new \SizeStation\Roundcube\Oidc\Session\OidcSession())->identity($_SESSION);
        if ($oidcIdentity === null || !class_exists('IdentSwitchSwitcher')) {
            return;
        }
        try {
            $rc = rcmail::get_instance();
            $switcher = new IdentSwitchSwitcher(new IdentSwitchCredentialService($rc));
            if ($switcher->switchAccountById((int) $recordId, false)) {
                $this->audit()->record(
                    \SizeStation\Roundcube\Oidc\Audit\AuditEvent::MailboxSwitch,
                    'system',
                    'preferred',
                    (int) $oidcIdentity['principal_id'],
                    metadata: ['ident_switch_record_id' => (int) $recordId],
                    sourceIp: $this->sourceIp(),
                );
                $rc->output->redirect(['_task' => 'mail', '_mbox' => 'INBOX']);
            }
        } catch (\Throwable $exception) {
            $this->failSafely('preferred_switch_failed', $exception, false);
        }
    }

    private function presentPendingAccountSelection(): void
    {
        if (empty($_SESSION['sizestation_oidc.account_selection_pending'])) {
            return;
        }
        $rc = rcmail::get_instance();
        $query = $rc->db->query(
            'SELECT id, label FROM ' . $rc->db->table_name('ident_switch')
            . ' WHERE user_id = ? AND managed_externally = 1 AND parent_id IS NULL AND flags & 1 > 0'
            . ' ORDER BY label, id',
            (int) $rc->user->ID,
        );
        $accounts = [[
            'id' => -1,
            'label' => (string) ($rc->user->data['username'] ?? $this->gettext('anchormailbox')),
        ]];
        while ($row = $rc->db->fetch_assoc($query)) {
            $accounts[] = ['id' => (int) $row['id'], 'label' => (string) $row['label']];
        }
        if (count($accounts) < 2) {
            unset($_SESSION['sizestation_oidc.account_selection_pending']);
            return;
        }
        $rc->output->set_env('sizestation_oidc_accounts', $accounts);
        $rc->output->set_env('sizestation_oidc_account_prompt', $this->gettext('selectmailbox'));
        $this->include_script('plugins/sizestation_oidc/account-select.js');
    }

    private function selectedManagedRecordAllowed(int $recordId, int $principalId): bool
    {
        $rc = rcmail::get_instance();
        $query = $rc->db->query(
            'SELECT s.id FROM ' . $rc->db->table_name('ident_switch') . ' s'
            . ' INNER JOIN ' . $rc->db->table_name('sizestation_mailbox_assignments') . ' a'
            . ' ON a.id = s.managed_assignment_id'
            . ' WHERE s.id = ? AND s.user_id = ? AND s.managed_externally = 1 AND s.flags & 1 > 0'
            . ' AND a.principal_id = ? AND a.enabled = 1',
            $recordId,
            (int) $rc->user->ID,
            $principalId,
        );

        return is_array($rc->db->fetch_assoc($query));
    }

    private function returnFromDisabledManagedAssignment(): bool
    {
        $activeIdentityId = $_SESSION['iid' . (defined('ident_switch::MY_POSTFIX')
            ? ident_switch::MY_POSTFIX
            : '_iswitch')] ?? null;
        if (!is_int($activeIdentityId) && !(is_string($activeIdentityId) && ctype_digit($activeIdentityId))) {
            return false;
        }
        $rc = rcmail::get_instance();
        $mustReturn = (new \SizeStation\Roundcube\Oidc\Service\ActiveManagedAssignmentGuard($rc->db))
            ->mustReturnToAnchor((int) $rc->user->ID, (int) $activeIdentityId);
        if (!$mustReturn) {
            return false;
        }
        try {
            if (!class_exists('IdentSwitchSwitcher')) {
                throw new \RuntimeException('The account switcher is unavailable');
            }
            $switcher = new IdentSwitchSwitcher(new IdentSwitchCredentialService($rc));
            if (!$switcher->switchAccountById(-1, false)) {
                throw new \RuntimeException('Unable to return to the anchor mailbox');
            }
            $rc->output->show_message($this->gettext('secondaryunavailable'), 'warning');
            $rc->output->redirect(['_task' => 'mail', '_mbox' => 'INBOX']);
        } catch (\Throwable $exception) {
            $this->failSafely('disabled_active_assignment', $exception, false);
            $rc->kill_session();
            $rc->output->redirect(['_task' => 'login']);
        }

        return true;
    }

    private function flow(): \SizeStation\Roundcube\Oidc\Oidc\OidcFlowService
    {
        $rc = rcmail::get_instance();
        $issuer = $this->requiredConfig('sizestation_oidc.issuer');
        $clientId = $this->requiredConfig('sizestation_oidc.client_id');
        $config = new \SizeStation\Roundcube\Oidc\Oidc\OidcClientConfig(
            $issuer,
            $clientId,
            $this->requiredConfig('sizestation_oidc.client_secret_file'),
            $this->requiredConfig('sizestation_oidc.redirect_uri'),
            (array) $rc->config->get('sizestation_oidc.scopes', ['openid', 'profile', 'email']),
            (string) $rc->config->get('sizestation_oidc.ca_file', ''),
            (int) $rc->config->get('sizestation_oidc.connect_timeout_seconds', 2),
            (int) $rc->config->get('sizestation_oidc.request_timeout_seconds', 5),
        );
        $validation = new \SizeStation\Roundcube\Oidc\Security\TokenValidationConfig(
            $issuer,
            $clientId,
            (string) $rc->config->get('sizestation_oidc.external_user_id_claim', 'sub'),
            (array) $rc->config->get('sizestation_oidc.allowed_algorithms', ['RS256']),
            (array) $rc->config->get('sizestation_oidc.allowed_groups', []),
            (string) $rc->config->get('sizestation_oidc.groups_claim', 'groups'),
            (int) $rc->config->get('sizestation_oidc.clock_tolerance_seconds', 60),
        );

        return new \SizeStation\Roundcube\Oidc\Oidc\OidcFlowService(
            $config,
            new \SizeStation\Roundcube\Oidc\Security\IdTokenValidator($validation),
            callbackSecurity: new \SizeStation\Roundcube\Oidc\Repository\CallbackSecurityRepository($rc->db),
        );
    }

    private function bootstrap(): \SizeStation\Roundcube\Oidc\Service\LoginBootstrapService
    {
        return new \SizeStation\Roundcube\Oidc\Service\LoginBootstrapService(
            $this->principals(),
            $this->assignments(),
            $this->audit(),
        );
    }

    private function principals(): \SizeStation\Roundcube\Oidc\Repository\PrincipalRepository
    {
        return new \SizeStation\Roundcube\Oidc\Repository\PrincipalRepository(rcmail::get_instance()->db);
    }

    private function assignments(): \SizeStation\Roundcube\Oidc\Repository\AssignmentRepository
    {
        return new \SizeStation\Roundcube\Oidc\Repository\AssignmentRepository(rcmail::get_instance()->db);
    }

    private function runtimeIdentityGuard(): \SizeStation\Roundcube\Oidc\Service\RuntimeIdentityGuard
    {
        $rc = rcmail::get_instance();

        return new \SizeStation\Roundcube\Oidc\Service\RuntimeIdentityGuard(
            $this->principals(),
            $rc->db,
        );
    }

    private function audit(): \SizeStation\Roundcube\Oidc\Repository\AuditLogRepository
    {
        return new \SizeStation\Roundcube\Oidc\Repository\AuditLogRepository(rcmail::get_instance()->db);
    }

    private function anchorCredentials(): \SizeStation\Roundcube\Oidc\Service\AnchorCredentialResolver
    {
        $config = rcmail::get_instance()->config;
        $clientConfig = new \SizeStation\Roundcube\Credentials\OpenBao\OpenBaoClientConfig(
            $this->requiredConfig('sizestation_oidc.openbao_address'),
            $this->requiredConfig('sizestation_oidc.openbao_token_file'),
            (string) $config->get('sizestation_oidc.openbao_kv_mount', 'secret'),
            (string) $config->get('sizestation_oidc.openbao_base_path', 'roundcube/mailboxes'),
            $this->requiredConfig('sizestation_oidc.openbao_ca_file'),
            (int) $config->get('sizestation_oidc.openbao_connect_timeout_seconds', 2),
            (int) $config->get('sizestation_oidc.openbao_request_timeout_seconds', 5),
        );

        return new \SizeStation\Roundcube\Oidc\Service\AnchorCredentialResolver(
            new \SizeStation\Roundcube\Credentials\Provider\OpenBaoCredentialProvider(
                new \SizeStation\Roundcube\Credentials\OpenBao\OpenBaoKvV2Client($clientConfig),
            ),
        );
    }

    private function enabled(): bool
    {
        return (bool) rcmail::get_instance()->config->get('sizestation_oidc.enabled', false);
    }

    private function requiredConfig(string $key): string
    {
        $value = rcmail::get_instance()->config->get($key);
        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException("Required configuration {$key} is missing");
        }

        return trim($value);
    }

    private function sourceIp(): ?string
    {
        $ip = class_exists('rcube_utils') ? rcube_utils::remote_addr() : ($_SERVER['REMOTE_ADDR'] ?? null);

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    private function sourceKey(): string
    {
        return $this->sourceIp() ?? 'unknown';
    }

    private function failSafely(string $code, \Throwable $exception, bool $show = true): void
    {
        $correlation = bin2hex(random_bytes(8));
        $identity = (new \SizeStation\Roundcube\Oidc\Session\OidcSession())->identity($_SESSION);
        $principalId = is_array($identity) ? (int) ($identity['principal_id'] ?? 0) : 0;
        try {
            $this->audit()->record(
                \SizeStation\Roundcube\Oidc\Audit\AuditEvent::OidcLoginFailure,
                'system',
                'roundcube',
                $principalId > 0 ? $principalId : null,
                metadata: ['error_code' => $code, 'correlation_id' => $correlation],
                sourceIp: $this->sourceIp(),
                userAgent: is_string($_SERVER['HTTP_USER_AGENT'] ?? null)
                    ? $_SERVER['HTTP_USER_AGENT']
                    : null,
            );
        } catch (\Throwable) {
        }
        rcmail::get_instance()->write_log(
            'sizestation_oidc',
            "event=oidc_failure code={$code} correlation_id={$correlation} exception=" . get_class($exception),
        );
        if ($show) {
            rcmail::get_instance()->output->show_message($this->gettext('loginunavailable'), 'error');
        }
    }

    private function loadSizeStationAutoloader(): void
    {
        $autoloaders = [
            '/opt/sizestation/vendor/autoload.php',
            dirname(__DIR__, 4) . '/vendor/autoload.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php',
        ];
        foreach ($autoloaders as $autoloader) {
            if (is_file($autoloader)) {
                require_once $autoloader;

                return;
            }
        }
    }
}
