<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Cli;

use Closure;
use RuntimeException;
use SizeStation\Roundcube\Credentials\AccountCredentials;
use SizeStation\Roundcube\Credentials\Exception\ExternalCredentialException;
use SizeStation\Roundcube\Credentials\OpenBao\CredentialReference;
use SizeStation\Roundcube\Oidc\Domain\MailboxAddress;
use SizeStation\Roundcube\Oidc\Audit\AuditEvent;
use SizeStation\Roundcube\Oidc\Provisioning\MailboxCredentialValidator;
use SizeStation\Roundcube\Oidc\Provisioning\MailboxValidationException;
use SizeStation\Roundcube\Oidc\Provisioning\SecretProvisionerInterface;
use SizeStation\Roundcube\Oidc\Reconciliation\AssignmentReconciler;
use SizeStation\Roundcube\Oidc\Repository\AdminRepository;
use SizeStation\Roundcube\Oidc\Repository\AssignmentRepository;
use SizeStation\Roundcube\Oidc\Repository\AuditLogRepository;

final class Application
{
    /** @var Closure(array<string, mixed>): AccountCredentials */
    private readonly Closure $credentialResolver;

    /** @param callable(array<string, mixed>): AccountCredentials $credentialResolver */
    public function __construct(
        private readonly \rcube_db $database,
        private readonly SecretProvisionerInterface $provisioner,
        callable $credentialResolver,
        private readonly ?MailboxCredentialValidator $validator = null,
        private readonly bool $validateImap = true,
        private readonly bool $validateSmtp = true,
    ) {
        $this->credentialResolver = Closure::fromCallable($credentialResolver);
    }

    /** @param list<string> $argv
     *  @param resource $stdin
     *  @param callable(string): void $stdout
     *  @param callable(string): void $stderr
     */
    public function run(array $argv, $stdin, callable $stdout, callable $stderr): int
    {
        $json = in_array('--json', $argv, true);
        try {
            $command = $argv[1] ?? 'help';
            $options = $this->options(array_slice($argv, 2));
            $json = $this->jsonOutput($options, $json);
            $result = match ($command) {
                'provision', 'assignment:create' => $this->provision($options, $stdin),
                'rotate', 'assignment:rotate-secret' => $this->rotate($options, $stdin),
                'validate', 'assignment:validate' => $this->validate($options),
                'disable', 'assignment:disable' => $this->disable($options),
                'assignment:enable' => $this->enableAssignment($options),
                'remove', 'assignment:remove' => $this->remove($options),
                'set-preferred', 'assignment:set-preferred' => $this->setPreferred($options),
                'assignment:clear-preferred' => $this->clearPreferred($options),
                'assignment:set-anchor' => $this->setAnchor($options),
                'assignment:list' => $this->listAssignments($options),
                'assignment:show' => $this->showAssignment($options),
                'disable-principal', 'principal:disable' => $this->disablePrincipal($options),
                'principal:enable' => $this->enablePrincipal($options),
                'principal:list' => $this->listPrincipals($options),
                'principal:show' => $this->showPrincipal($options),
                'sync:user', 'reconcile:user' => $this->reconcileUser($options),
                'reconcile', 'sync:all', 'reconcile:all' => $this->reconcile($options),
                'audit', 'audit:list' => $this->audit($options),
                'help', '--help', '-h' => ['help' => self::usage()],
                default => throw new RuntimeException('Unknown administrative command'),
            };
            $this->emit($stdout, $result, $json);

            return 0;
        } catch (\Throwable $exception) {
            $payload = ['ok' => false, 'error_code' => $this->errorCode($exception)];
            $this->emit($stderr, $payload, $json);

            return 1;
        }
    }

    /** @param array<string, string|bool> $options
     *  @param resource $stdin
     *  @return array<string, mixed>
     */
    private function provision(array $options, $stdin): array
    {
        $issuer = $this->required($options, 'issuer');
        $externalId = $this->required($options, 'external-user-id');
        $mailbox = $this->required($options, 'mailbox');
        $reference = new CredentialReference($this->required($options, 'credential-reference'));
        $anchor = !empty($options['anchor']);
        $preferred = !empty($options['preferred']);
        $dryRun = !empty($options['dry-run']);
        $username = is_string($options['username'] ?? null) ? $options['username'] : $mailbox;
        $this->assertMailboxUsername($mailbox, $username);
        $createsSecret = !empty($options['password-stdin']);
        $password = '';
        $credentials = null;
        try {
            if ($createsSecret) {
                $password = $this->password($options, $stdin);
                $credentials = new AccountCredentials($username, $password);
            } else {
                $credentials = ($this->credentialResolver)([
                    'id' => '00000000-0000-4000-8000-000000000000',
                    'mailbox_address' => $mailbox,
                    'credential_provider' => 'openbao',
                    'credential_reference' => $reference->value,
                    'managed_externally' => 1,
                ]);
                $this->assertMailboxUsername($mailbox, $credentials->imapUsername());
            }
            $this->validator?->validate($credentials, $this->validateImap, $this->validateSmtp);
            if ($dryRun) {
                return [
                    'ok' => true,
                    'dry_run' => true,
                    'command' => 'assignment:create',
                    'mailbox' => $mailbox,
                    'database_changes' => ['create pending mailbox assignment'],
                    'openbao_paths' => [$reference->value],
                    'ident_switch_record_ids' => [],
                    'roundcube_identity_ids' => [],
                    'validation_actions' => array_merge(
                        $createsSecret ? [] : ['read existing OpenBao credential'],
                        $this->validationActions(),
                    ),
                ];
            }

            if ($createsSecret) {
                $this->provisioner->create($reference, ['username' => $username, 'password' => $password]);
            }
            $repository = new AdminRepository($this->database);
            try {
                $assignment = $repository->createAssignment(
                    $issuer,
                    $externalId,
                    $mailbox,
                    $reference->value,
                    is_string($options['label'] ?? null) ? $options['label'] : null,
                    $anchor,
                    $preferred,
                    'cli',
                    'valid',
                );
            } catch (\Throwable $exception) {
                if ($createsSecret) {
                    try {
                        $this->provisioner->delete($reference);
                    } catch (\Throwable) {
                    }
                }
                throw $exception;
            }
            $this->auditRepository()->record(
                AuditEvent::AssignmentCreated,
                'cli',
                'administrator',
                $assignment['principal_id'] === null ? null : (int) $assignment['principal_id'],
                (string) $assignment['id'],
                ['mailbox' => $assignment['mailbox_address']],
            );
            $this->reconcilePrincipalOf($assignment);

            return [
                'ok' => true,
                'assignment_id' => $assignment['id'],
                'mailbox' => $assignment['mailbox_address'],
                'anchor' => (bool) $assignment['is_anchor'],
                'preferred' => (bool) $assignment['is_preferred'],
            ];
        } finally {
            $credentials?->erase();
            $this->erase($password);
        }
    }

    /** @param array<string, string|bool> $options
     *  @param resource $stdin
     *  @return array<string, mixed>
     */
    private function rotate(array $options, $stdin): array
    {
        $assignment = $this->assignment($options);
        $reference = new CredentialReference((string) $assignment['credential_reference']);
        $username = is_string($options['username'] ?? null)
            ? $options['username']
            : (string) $assignment['mailbox_address'];
        $this->assertMailboxUsername((string) $assignment['mailbox_address'], $username);
        $password = $this->password($options, $stdin);
        try {
            $credentials = new AccountCredentials($username, $password);
            $this->validator?->validate($credentials, $this->validateImap, $this->validateSmtp);
            if (!empty($options['dry-run'])) {
                return $this->assignmentDryRun(
                    'assignment:rotate-secret',
                    $assignment,
                    ['write a new OpenBao KV v2 secret version', 'mark credential valid'],
                    [$reference->value],
                    $this->validationActions(),
                );
            }
            $this->provisioner->write($reference, ['username' => $username, 'password' => $password]);
            (new AdminRepository($this->database))->markCredentialStatus((string) $assignment['id'], 'valid');
            $this->auditRepository()->record(
                AuditEvent::CredentialRotated,
                'cli',
                'administrator',
                $assignment['principal_id'] === null ? null : (int) $assignment['principal_id'],
                (string) $assignment['id'],
            );

            return ['ok' => true, 'assignment_id' => $assignment['id'], 'credential_status' => 'valid'];
        } finally {
            $this->erase($password);
        }
    }

    /** @param array<string, string|bool> $options
     *  @return array<string, mixed>
     */
    private function validate(array $options): array
    {
        $assignment = $this->assignment($options);
        if (!empty($options['dry-run'])) {
            return $this->assignmentDryRun(
                'assignment:validate',
                $assignment,
                ['update credential validation status'],
                [(string) $assignment['credential_reference']],
                array_merge(['read OpenBao credential'], $this->validationActions()),
            );
        }
        $status = $this->validateAssignmentCredential($assignment);

        return ['ok' => true, 'assignment_id' => $assignment['id'], 'credential_status' => $status];
    }

    /** @param array<string, string|bool> $options
     *  @return array<string, mixed>
     */
    private function disable(array $options): array
    {
        $assignment = $this->assignment($options);
        if (!empty($options['dry-run'])) {
            return $this->assignmentDryRun(
                'assignment:disable',
                $assignment,
                ['disable assignment', 'disable managed switch record during reconciliation'],
            );
        }
        $assignment = (new AdminRepository($this->database))->disableAssignment((string) $assignment['id']);
        $this->auditRepository()->record(
            AuditEvent::AssignmentDisabled,
            'cli',
            'administrator',
            $assignment['principal_id'] === null ? null : (int) $assignment['principal_id'],
            (string) $assignment['id'],
        );
        $this->reconcilePrincipalOf($assignment);

        return ['ok' => true, 'assignment_id' => $assignment['id'], 'enabled' => false];
    }

    /** @param array<string, string|bool> $options
     *  @return array<string, mixed>
     */
    private function enableAssignment(array $options): array
    {
        $assignment = $this->assignment($options);
        if (!empty($options['dry-run'])) {
            return $this->assignmentDryRun(
                'assignment:enable',
                $assignment,
                ['enable assignment', 'materialize managed switch record during reconciliation'],
            );
        }
        $assignment = (new AdminRepository($this->database))->enableAssignment((string) $assignment['id']);
        $this->auditRepository()->record(
            AuditEvent::AssignmentEnabled,
            'cli',
            'administrator',
            $assignment['principal_id'] === null ? null : (int) $assignment['principal_id'],
            (string) $assignment['id'],
        );
        $this->reconcilePrincipalOf($assignment);

        return ['ok' => true, 'assignment_id' => $assignment['id'], 'enabled' => true];
    }

    /** @param array<string, string|bool> $options
     *  @return array<string, mixed>
     */
    private function remove(array $options): array
    {
        $assignment = $this->assignment($options);
        if (!empty($options['dry-run'])) {
            return $this->assignmentDryRun(
                'assignment:remove',
                $assignment,
                ['retire assignment', 'disable managed switch record during reconciliation'],
                !empty($options['delete-secret']) ? [(string) $assignment['credential_reference']] : [],
            );
        }
        $removed = (new AdminRepository($this->database))->removeAssignment((string) $assignment['id']);
        $this->auditRepository()->record(
            AuditEvent::AssignmentRemoved,
            'cli',
            'administrator',
            $removed['principal_id'] === null ? null : (int) $removed['principal_id'],
            (string) $removed['id'],
        );
        if (!empty($options['delete-secret'])) {
            $this->provisioner->delete(new CredentialReference((string) $removed['credential_reference']));
        }
        $this->reconcilePrincipalOf($removed);

        return ['ok' => true, 'assignment_id' => $removed['id'], 'removed' => true];
    }

    /** @param array<string, string|bool> $options
     *  @return array<string, mixed>
     */
    private function setPreferred(array $options): array
    {
        $assignment = $this->assignment($options);
        if (!empty($options['dry-run'])) {
            return $this->assignmentDryRun(
                'assignment:set-preferred',
                $assignment,
                ['clear current preferred assignment', 'select requested preferred assignment'],
            );
        }
        $assignment = (new AdminRepository($this->database))->setPreferred((string) $assignment['id']);
        $this->auditRepository()->record(
            AuditEvent::PreferredAccountChanged,
            'cli',
            'administrator',
            $assignment['principal_id'] === null ? null : (int) $assignment['principal_id'],
            (string) $assignment['id'],
        );

        return ['ok' => true, 'assignment_id' => $assignment['id'], 'preferred' => true];
    }

    /** @param array<string, string|bool> $options
     *  @return array<string, mixed>
     */
    private function clearPreferred(array $options): array
    {
        $assignment = $this->assignment($options);
        if (!empty($options['dry-run'])) {
            return $this->assignmentDryRun(
                'assignment:clear-preferred',
                $assignment,
                ['clear preferred assignment'],
            );
        }
        $assignment = (new AdminRepository($this->database))->clearPreferred((string) $assignment['id']);
        $this->auditRepository()->record(
            AuditEvent::PreferredAccountChanged,
            'cli',
            'administrator',
            $assignment['principal_id'] === null ? null : (int) $assignment['principal_id'],
            (string) $assignment['id'],
            ['preferred' => false],
        );

        return ['ok' => true, 'assignment_id' => $assignment['id'], 'preferred' => false];
    }

    /** @param array<string, string|bool> $options
     *  @return array<string, mixed>
     */
    private function setAnchor(array $options): array
    {
        $assignment = $this->assignment($options);
        if (!empty($options['dry-run'])) {
            return $this->assignmentDryRun(
                'assignment:set-anchor',
                $assignment,
                ['clear pre-login anchor', 'select requested pre-login anchor'],
            );
        }
        $assignment = (new AdminRepository($this->database))->setAnchorBeforeInitialization(
            (string) $assignment['id'],
        );
        $this->auditRepository()->record(
            AuditEvent::AnchorSelected,
            'cli',
            'administrator',
            $assignment['principal_id'] === null ? null : (int) $assignment['principal_id'],
            (string) $assignment['id'],
        );

        return ['ok' => true, 'assignment_id' => $assignment['id'], 'anchor' => true];
    }

    /** @param array<string, string|bool> $options
     *  @return array<string, mixed>
     */
    private function listAssignments(array $options): array
    {
        $principalId = isset($options['principal-id']) ? $this->integer($options, 'principal-id') : null;

        return ['ok' => true, 'assignments' => (new AdminRepository($this->database))->assignments($principalId)];
    }

    /** @param array<string, string|bool> $options
     *  @return array<string, mixed>
     */
    private function showAssignment(array $options): array
    {
        return ['ok' => true, 'assignment' => $this->assignment($options)];
    }

    /** @param array<string, string|bool> $options
     *  @return array<string, mixed>
     */
    private function disablePrincipal(array $options): array
    {
        $principalId = $this->integer($options, 'principal-id');
        if (!empty($options['dry-run'])) {
            return ['ok' => true, 'dry_run' => true, 'command' => 'principal:disable',
                'principal_id' => $principalId, 'database_changes' => ['disable principal']];
        }
        (new AdminRepository($this->database))->disablePrincipal($principalId);
        $this->auditRepository()->record(AuditEvent::PrincipalDisabled, 'cli', 'administrator', $principalId);

        return ['ok' => true, 'principal_id' => $principalId, 'status' => 'disabled'];
    }

    /** @param array<string, string|bool> $options
     *  @return array<string, mixed>
     */
    private function enablePrincipal(array $options): array
    {
        $principalId = $this->integer($options, 'principal-id');
        if (!empty($options['dry-run'])) {
            return ['ok' => true, 'dry_run' => true, 'command' => 'principal:enable',
                'principal_id' => $principalId, 'database_changes' => ['enable principal']];
        }
        $principal = (new AdminRepository($this->database))->enablePrincipal($principalId);
        $this->auditRepository()->record(AuditEvent::PrincipalEnabled, 'cli', 'administrator', $principalId);

        return ['ok' => true, 'principal_id' => $principalId, 'status' => $principal['status']];
    }

    /** @param array<string, string|bool> $options
     *  @return array<string, mixed>
     */
    private function listPrincipals(array $options): array
    {
        $principalId = isset($options['principal-id']) ? $this->integer($options, 'principal-id') : null;

        return ['ok' => true, 'principals' => (new AdminRepository($this->database))->principals($principalId)];
    }

    /** @param array<string, string|bool> $options
     *  @return array<string, mixed>
     */
    private function showPrincipal(array $options): array
    {
        $principalId = $this->integer($options, 'principal-id');
        $principals = (new AdminRepository($this->database))->principals($principalId);
        if ($principals === []) {
            throw new RuntimeException('Principal was not found');
        }

        return ['ok' => true, 'principal' => $principals[0]];
    }

    /** @param array<string, string|bool> $options
     *  @return array<string, mixed>
     */
    private function reconcile(array $options): array
    {
        $principalId = isset($options['principal-id']) ? $this->integer($options, 'principal-id') : null;
        $principals = (new AdminRepository($this->database))->principals($principalId);
        $results = [];
        foreach ($principals as $principal) {
            if ($principal['roundcube_user_id'] === null) {
                $results[] = ['principal_id' => (int) $principal['id'], 'status' => 'pending'];
                continue;
            }
            if (!empty($options['dry-run'])) {
                $assignments = (new AdminRepository($this->database))->assignments((int) $principal['id']);
                $results[] = [
                    'principal_id' => (int) $principal['id'],
                    'status' => 'dry-run',
                    'database_changes' => ['reconcile assignments and managed materialization state'],
                    'assignment_ids' => array_column($assignments, 'id'),
                    'ident_switch_record_ids' => array_values(array_filter(array_column(
                        $assignments,
                        'ident_switch_record_id',
                    ))),
                    'roundcube_identity_ids' => array_values(array_filter(array_column(
                        $assignments,
                        'roundcube_identity_id',
                    ))),
                    'validation_actions' => array_merge(
                        ['read enabled OpenBao credentials'],
                        $this->validationActions(),
                    ),
                ];
                continue;
            }
            $id = (int) $principal['id'];
            $this->auditRepository()->record(AuditEvent::ReconciliationStarted, 'cli', 'administrator', $id);
            try {
                $result = $this->reconcilePrincipal($principal);
                foreach ($result->materialized as $materialized) {
                    $this->auditRepository()->record(
                        AuditEvent::AssignmentMaterialized,
                        'cli',
                        'administrator',
                        $id,
                        $materialized['assignment_id'],
                        [
                            'ident_switch_record_id' => $materialized['record_id'],
                            'roundcube_identity_id' => $materialized['identity_id'],
                        ],
                    );
                }
                $this->auditRepository()->record(
                    AuditEvent::ReconciliationCompleted,
                    'cli',
                    'administrator',
                    $id,
                    metadata: [
                        'created' => $result->created,
                        'updated' => $result->updated,
                        'disabled' => $result->disabled,
                        'orphaned' => $result->orphaned,
                    ],
                );
            } catch (\Throwable $exception) {
                $this->auditRepository()->record(
                    AuditEvent::ReconciliationFailed,
                    'cli',
                    'administrator',
                    $id,
                    metadata: ['error_code' => 'reconciliation_failed'],
                );
                throw $exception;
            }
            $results[] = [
                'principal_id' => (int) $principal['id'],
                'created' => $result->created,
                'updated' => $result->updated,
                'disabled' => $result->disabled,
                'orphaned' => $result->orphaned,
            ];
        }

        return ['ok' => true, 'results' => $results];
    }

    /** @param array<string, string|bool> $options
     *  @return array<string, mixed>
     */
    private function reconcileUser(array $options): array
    {
        $this->integer($options, 'principal-id');

        return $this->reconcile($options);
    }

    /** @param array<string, string|bool> $options
     *  @return array<string, mixed>
     */
    private function audit(array $options): array
    {
        $principalId = isset($options['principal-id']) ? $this->integer($options, 'principal-id') : null;
        $limit = isset($options['limit']) ? $this->integer($options, 'limit') : 100;

        return ['ok' => true, 'events' => (new AdminRepository($this->database))->audit($principalId, $limit)];
    }

    /** @param array<string, mixed> $assignment */
    private function reconcilePrincipalOf(array $assignment): void
    {
        if ($assignment['principal_id'] === null) {
            return;
        }
        $principals = (new AdminRepository($this->database))->principals((int) $assignment['principal_id']);
        if (
            $principals !== []
            && $principals[0]['roundcube_user_id'] !== null
            && $principals[0]['status'] === 'active'
        ) {
            $this->reconcilePrincipal($principals[0]);
        }
    }

    /** @param array<string, mixed> $principal */
    private function reconcilePrincipal(
        array $principal,
    ): \SizeStation\Roundcube\Oidc\Reconciliation\ReconciliationResult {
        $principalId = (int) $principal['id'];
        $assignments = (new AssignmentRepository($this->database))->forPrincipal(
            $principalId,
            (string) $principal['issuer'],
            (string) $principal['external_user_id'],
        );
        foreach ($assignments as $assignment) {
            if (empty($assignment['enabled'])) {
                continue;
            }
            try {
                $this->validateAssignmentCredential($assignment);
            } catch (\Throwable) {
                // Credential health is recorded, but reconciliation must remain repairable and idempotent.
            }
        }

        return (new AssignmentReconciler($this->database))->reconcile(
            $principalId,
            (int) $principal['roundcube_user_id'],
            $assignments,
        );
    }

    /** @param array<string, string|bool> $options
     *  @return array<string, mixed>
     */
    private function assignment(array $options): array
    {
        $id = $this->required($options, 'assignment-id');

        return (new AdminRepository($this->database))->assignment($id)
            ?? throw new RuntimeException('Assignment was not found');
    }

    private function auditRepository(): AuditLogRepository
    {
        return new AuditLogRepository($this->database);
    }

    /** @param array<string, mixed> $assignment */
    private function validateAssignmentCredential(array $assignment): string
    {
        $repository = new AdminRepository($this->database);
        $credentials = null;
        try {
            $credentials = ($this->credentialResolver)($assignment);
            $this->validator?->validate($credentials, $this->validateImap, $this->validateSmtp);
            $repository->markCredentialStatus((string) $assignment['id'], 'valid');
            $this->auditRepository()->record(
                AuditEvent::CredentialValidationSuccess,
                'cli',
                'administrator',
                $assignment['principal_id'] === null ? null : (int) $assignment['principal_id'],
                (string) $assignment['id'],
            );

            return 'valid';
        } catch (\Throwable $exception) {
            $typed = $exception instanceof ExternalCredentialException
                || $exception instanceof MailboxValidationException;
            $errorCode = $typed ? $exception->errorCode : 'credential_validation_failed';
            $unavailable = $typed
                && $exception->kind !== \SizeStation\Roundcube\Credentials\Exception\CredentialFailureKind::Invalid;
            if ($unavailable) {
                $repository->recordCredentialAvailabilityFailure((string) $assignment['id'], $errorCode);
            } else {
                $repository->markCredentialStatus((string) $assignment['id'], 'invalid', $errorCode);
            }
            $this->auditRepository()->record(
                $exception instanceof ExternalCredentialException && $unavailable
                    ? AuditEvent::OpenBaoUnavailable
                    : AuditEvent::CredentialValidationFailure,
                'cli',
                'administrator',
                $assignment['principal_id'] === null ? null : (int) $assignment['principal_id'],
                (string) $assignment['id'],
                ['error_code' => $errorCode, 'unavailable' => $unavailable],
            );
            throw $exception;
        } finally {
            $credentials?->erase();
        }
    }

    /** @param list<string> $arguments
     *  @return array<string, string|bool>
     */
    private function options(array $arguments): array
    {
        $options = [];
        for ($index = 0; $index < count($arguments); ++$index) {
            $argument = $arguments[$index];
            if (!str_starts_with($argument, '--')) {
                throw new RuntimeException('Unexpected positional argument');
            }
            $argument = substr($argument, 2);
            if (str_contains($argument, '=')) {
                [$name, $value] = explode('=', $argument, 2);
                $options[$name] = $value;
                continue;
            }
            $name = $argument;
            $next = $arguments[$index + 1] ?? null;
            if (is_string($next) && !str_starts_with($next, '--')) {
                $options[$name] = $next;
                ++$index;
            } else {
                $options[$name] = true;
            }
        }
        if (isset($options['password'])) {
            throw new RuntimeException('Passwords must be supplied through standard input');
        }

        return $options;
    }

    /** @param array<string, string|bool> $options */
    private function required(array $options, string $name): string
    {
        $value = $options[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException("Required option --{$name} is missing");
        }

        return $value;
    }

    /** @param array<string, string|bool> $options */
    private function integer(array $options, string $name): int
    {
        $value = $this->required($options, $name);
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new RuntimeException("Option --{$name} must be a positive integer");
        }

        return (int) $value;
    }

    /** @param array<string, string|bool> $options */
    private function jsonOutput(array $options, bool $legacyJson): bool
    {
        $format = $options['format'] ?? null;
        if ($format === null) {
            return $legacyJson;
        }
        if (!is_string($format) || !in_array($format, ['human', 'json'], true)) {
            throw new RuntimeException('Option --format must be human or json');
        }

        return $format === 'json';
    }

    /** @param array<string, string|bool> $options
     *  @param resource $stdin
     */
    private function password(array $options, $stdin): string
    {
        if (empty($options['password-stdin'])) {
            throw new RuntimeException('The --password-stdin option is required');
        }
        $password = stream_get_contents($stdin, 4097);
        if (!is_string($password)) {
            throw new RuntimeException('Unable to read password from standard input');
        }
        $password = rtrim($password, "\r\n");
        if ($password === '' || strlen($password) > 4096) {
            throw new RuntimeException('Password from standard input is invalid');
        }

        return $password;
    }

    /** @param array<string, mixed> $payload
     *  @param callable(string): void $writer
     */
    private function emit(callable $writer, array $payload, bool $json): void
    {
        if ($json) {
            $writer(json_encode($payload, JSON_THROW_ON_ERROR) . PHP_EOL);

            return;
        }
        if (isset($payload['help'])) {
            $writer((string) $payload['help']);

            return;
        }
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $writer($key . '=' . json_encode($value, JSON_THROW_ON_ERROR) . PHP_EOL);
            } else {
                $writer($key . '=' . var_export($value, true) . PHP_EOL);
            }
        }
    }

    private function errorCode(\Throwable $exception): string
    {
        return match (true) {
            $exception instanceof \InvalidArgumentException => 'invalid_argument',
            $exception instanceof RuntimeException => 'operation_rejected',
            default => 'operation_failed',
        };
    }

    private function erase(string &$value): void
    {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($value);
        }
        $value = '';
    }

    private function assertMailboxUsername(string $mailbox, string $username): void
    {
        $expected = (string) new MailboxAddress($mailbox);
        $actual = (string) new MailboxAddress($username);
        if (!hash_equals($expected, $actual)) {
            throw new RuntimeException('Managed credential username must match the assignment mailbox');
        }
    }

    /** @param array<string, mixed> $assignment
     *  @param list<string> $databaseChanges
     *  @param list<string> $openBaoPaths
     *  @param list<string> $validationActions
     *  @return array<string, mixed>
     */
    private function assignmentDryRun(
        string $command,
        array $assignment,
        array $databaseChanges,
        array $openBaoPaths = [],
        array $validationActions = [],
    ): array {
        return [
            'ok' => true,
            'dry_run' => true,
            'command' => $command,
            'assignment_id' => $assignment['id'],
            'database_changes' => $databaseChanges,
            'openbao_paths' => $openBaoPaths,
            'ident_switch_record_ids' => $assignment['ident_switch_record_id'] === null
                ? []
                : [(int) $assignment['ident_switch_record_id']],
            'roundcube_identity_ids' => $assignment['roundcube_identity_id'] === null
                ? []
                : [(int) $assignment['roundcube_identity_id']],
            'validation_actions' => $validationActions,
        ];
    }

    /** @return list<string> */
    private function validationActions(): array
    {
        $actions = [];
        if ($this->validator !== null && $this->validateImap) {
            $actions[] = 'validate fixed IMAP endpoint';
        }
        if ($this->validator !== null && $this->validateSmtp) {
            $actions[] = 'validate fixed SMTP endpoint';
        }

        return $actions;
    }

    public static function usage(): string
    {
        return <<<'USAGE'
SizeStation Roundcube OIDC administration

Commands:
  principal:list [--principal-id ID]
  principal:show --principal-id ID
  principal:disable|principal:enable --principal-id ID
  assignment:create --issuer URL --external-user-id ID --mailbox ADDRESS
                    --credential-reference PATH [--password-stdin] [--anchor] [--preferred]
  assignment:list [--principal-id ID]
  assignment:show --assignment-id UUID
  assignment:disable|assignment:enable|assignment:remove --assignment-id UUID
  assignment:set-anchor|assignment:set-preferred|assignment:clear-preferred --assignment-id UUID
  assignment:validate --assignment-id UUID
  assignment:rotate-secret --assignment-id UUID --password-stdin [--username ADDRESS]
  sync:user|reconcile:user --principal-id ID
  sync:all|reconcile:all
  audit:list [--principal-id ID] [--limit NUMBER]

Mutation commands support --dry-run. All commands support --format human|json.
The legacy --json flag remains accepted for compatibility.
Passwords are accepted only from standard input and are never printed.
Legacy shorthand commands remain accepted for compatibility.
USAGE;
    }
}
