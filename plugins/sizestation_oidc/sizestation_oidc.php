<?php

/**
 * Authentik OIDC and OpenBao-managed mailbox assignments for Roundcube.
 *
 * Copyright (C) 2026 SizeStation
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
class sizestation_oidc extends rcube_plugin
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
        $this->load_config();
        if (!$this->enabled()) {
            return;
        }

        $this->add_texts('localization/');
        $this->add_hook('startup', [$this, 'onStartup']);
        $this->add_hook('loginform_content', [$this, 'onLoginForm']);
        $this->add_hook('authenticate', [$this, 'onAuthenticate']);
        $this->add_hook('login_after', [$this, 'onLoginAfter']);
        $this->add_hook('login_failed', [$this, 'onLoginFailed']);
        $this->add_hook('logout_after', [$this, 'onLogoutAfter']);
        $this->register_action('plugin.sizestation_oidc.login', [$this, 'noopAction']);
        $this->register_action('plugin.sizestation_oidc.callback', [$this, 'noopAction']);
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
        if ($task === 'mail') {
            $this->performPendingPreferredSwitch();

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
                $this->assignments()->markCredentialFailure(
                    (string) $this->loginPhase->anchor['id'],
                    (int) $this->loginPhase->principal['id'],
                    $status,
                    $errorCode,
                );
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
            $this->recordReconciliationCompleted($principalId, $result);
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
        }
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
            (array) $rc->config->get('sizestation_oidc.scopes', ['openid', 'profile', 'email', 'sizestation_user_id']),
            (string) $rc->config->get('sizestation_oidc.ca_file', ''),
            (int) $rc->config->get('sizestation_oidc.connect_timeout_seconds', 2),
            (int) $rc->config->get('sizestation_oidc.request_timeout_seconds', 5),
        );
        $validation = new \SizeStation\Roundcube\Oidc\Security\TokenValidationConfig(
            $issuer,
            $clientId,
            (string) $rc->config->get('sizestation_oidc.external_user_id_claim', 'sizestation_user_id'),
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
