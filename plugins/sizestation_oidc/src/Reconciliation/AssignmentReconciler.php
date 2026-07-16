<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Reconciliation;

use RuntimeException;
use SizeStation\Roundcube\Oidc\Domain\AssignmentSetValidator;

final class AssignmentReconciler
{
    private const ENABLED = 1;
    private const AUTH_IMAP = 1;

    public function __construct(
        private readonly \rcube_db $database,
        private readonly AssignmentSetValidator $validator = new AssignmentSetValidator(),
    ) {
    }

    /** @param list<array<string, mixed>> $assignments */
    public function reconcile(int $principalId, int $roundcubeUserId, array $assignments): ReconciliationResult
    {
        $this->validator->validateForLogin($assignments);
        if (!$this->database->startTransaction()) {
            throw new RuntimeException('Unable to start assignment reconciliation');
        }

        $created = $updated = $disabled = $orphaned = 0;
        $preferredRecordId = null;
        $known = [];
        try {
            foreach ($assignments as $assignment) {
                if ((int) ($assignment['principal_id'] ?? 0) !== $principalId) {
                    throw new RuntimeException('Assignment ownership changed during reconciliation');
                }
                $assignmentId = (string) $assignment['id'];
                $known[$assignmentId] = true;
                if (!empty($assignment['is_anchor'])) {
                    $this->updateAssignment($assignmentId, $principalId, 'anchor', null, null);
                    continue;
                }
                if (empty($assignment['enabled'])) {
                    if ($this->disableManagedRecord($assignmentId, $roundcubeUserId)) {
                        ++$disabled;
                    }
                    $this->updateAssignment($assignmentId, $principalId, 'disabled', null, null);
                    continue;
                }

                $record = $this->managedRecord($assignmentId, $roundcubeUserId);
                if ($record === null) {
                    $this->assertNotOwnedByAnotherUser($assignmentId, $roundcubeUserId);
                    $identityId = $this->createIdentity($roundcubeUserId, $assignment);
                    $recordId = $this->createManagedRecord($roundcubeUserId, $identityId, $assignment);
                    ++$created;
                } else {
                    $recordId = (int) $record['id'];
                    $identityId = $this->repairIdentity($record, $roundcubeUserId, $assignment);
                    $this->updateManagedRecord($recordId, $roundcubeUserId, $identityId, $assignment);
                    ++$updated;
                }
                $this->updateAssignment($assignmentId, $principalId, 'materialized', $recordId, $identityId);
                if (!empty($assignment['is_preferred'])) {
                    $preferredRecordId = $recordId;
                }
            }

            $query = $this->database->query(
                'SELECT id, managed_assignment_id FROM ' . $this->database->table_name('ident_switch')
                . ' WHERE user_id = ? AND managed_externally = ?',
                $roundcubeUserId,
                1,
            );
            while ($row = $this->database->fetch_assoc($query)) {
                if (!isset($known[(string) $row['managed_assignment_id']])) {
                    $this->setRecordEnabled((int) $row['id'], $roundcubeUserId, false);
                    ++$orphaned;
                }
            }

            if (!$this->database->endTransaction()) {
                throw new RuntimeException('Unable to commit assignment reconciliation');
            }

            return new ReconciliationResult($created, $updated, $disabled, $orphaned, $preferredRecordId);
        } catch (\Throwable $exception) {
            $this->database->rollbackTransaction();
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    private function managedRecord(string $assignmentId, int $userId): ?array
    {
        $query = $this->database->query(
            'SELECT id, iid, flags FROM ' . $this->database->table_name('ident_switch')
            . ' WHERE managed_assignment_id = ? AND user_id = ? AND managed_externally = ?',
            $assignmentId,
            $userId,
            1,
        );
        $row = $this->database->fetch_assoc($query);

        return is_array($row) ? $row : null;
    }

    private function assertNotOwnedByAnotherUser(string $assignmentId, int $userId): void
    {
        $query = $this->database->query(
            'SELECT user_id FROM ' . $this->database->table_name('ident_switch')
            . ' WHERE managed_assignment_id = ?',
            $assignmentId,
        );
        $row = $this->database->fetch_assoc($query);
        if (is_array($row) && (int) $row['user_id'] !== $userId) {
            throw new RuntimeException('Managed assignment is owned by another Roundcube user');
        }
    }

    /** @param array<string, mixed> $assignment */
    private function createIdentity(int $userId, array $assignment): int
    {
        $email = (string) $assignment['mailbox_address'];
        if (strlen($email) > 128) {
            throw new RuntimeException('Mailbox address exceeds the Roundcube identity limit');
        }
        $query = $this->database->query(
            'INSERT INTO ' . $this->database->table_name('identities')
            . ' (user_id, changed, del, standard, name, email, signature, html_signature)'
            . ' VALUES (?, ' . $this->database->now() . ', 0, 0, ?, ?, ?, 0)',
            $userId,
            $this->identityName($assignment),
            $email,
            '',
        );
        $identityId = $query ? (int) $this->database->insert_id('identities') : 0;
        if ($identityId < 1) {
            throw new RuntimeException('Unable to create managed Roundcube identity');
        }

        return $identityId;
    }

    /** @param array<string, mixed> $record
     *  @param array<string, mixed> $assignment
     */
    private function repairIdentity(array $record, int $userId, array $assignment): int
    {
        $identityId = (int) $record['iid'];
        $query = $this->database->query(
            'SELECT identity_id FROM ' . $this->database->table_name('identities')
            . ' WHERE identity_id = ? AND user_id = ? AND del = 0',
            $identityId,
            $userId,
        );
        if (!$this->database->fetch_assoc($query)) {
            return $this->createIdentity($userId, $assignment);
        }
        $updated = $this->database->query(
            'UPDATE ' . $this->database->table_name('identities')
            . ' SET changed = ' . $this->database->now() . ', name = ?, email = ?'
            . ' WHERE identity_id = ? AND user_id = ? AND del = 0',
            $this->identityName($assignment),
            (string) $assignment['mailbox_address'],
            $identityId,
            $userId,
        );
        if (!$updated) {
            throw new RuntimeException('Unable to update managed Roundcube identity');
        }

        return $identityId;
    }

    /** @param array<string, mixed> $assignment */
    private function createManagedRecord(int $userId, int $identityId, array $assignment): int
    {
        $label = $this->uniqueLabel($userId, $this->label($assignment), (string) $assignment['id']);
        $query = $this->database->query(
            'INSERT INTO ' . $this->database->table_name('ident_switch')
            . ' (user_id, iid, label, flags, smtp_auth, sieve_auth, credential_provider,'
            . ' credential_reference, managed_externally, managed_assignment_id, notify_check)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $userId,
            $identityId,
            $label,
            self::ENABLED,
            self::AUTH_IMAP,
            self::AUTH_IMAP,
            (string) $assignment['credential_provider'],
            (string) $assignment['credential_reference'],
            1,
            (string) $assignment['id'],
            1,
        );
        $recordId = $query ? (int) $this->database->insert_id('ident_switch') : 0;
        if ($recordId < 1) {
            throw new RuntimeException('Unable to create managed ident_switch record');
        }

        return $recordId;
    }

    /** @param array<string, mixed> $assignment */
    private function updateManagedRecord(int $recordId, int $userId, int $identityId, array $assignment): void
    {
        $label = $this->uniqueLabel(
            $userId,
            $this->label($assignment),
            (string) $assignment['id'],
            $recordId,
        );
        $query = $this->database->query(
            'UPDATE ' . $this->database->table_name('ident_switch')
            . ' SET iid = ?, label = ?, flags = flags | ?, credential_provider = ?,'
            . ' credential_reference = ?, managed_externally = ?'
            . ' WHERE id = ? AND user_id = ? AND managed_assignment_id = ?',
            $identityId,
            $label,
            self::ENABLED,
            (string) $assignment['credential_provider'],
            (string) $assignment['credential_reference'],
            1,
            $recordId,
            $userId,
            (string) $assignment['id'],
        );
        if (!$query) {
            throw new RuntimeException('Unable to update managed ident_switch record');
        }
    }

    private function disableManagedRecord(string $assignmentId, int $userId): bool
    {
        $record = $this->managedRecord($assignmentId, $userId);
        if ($record === null || (((int) $record['flags']) & self::ENABLED) === 0) {
            return false;
        }
        $this->setRecordEnabled((int) $record['id'], $userId, false);

        return true;
    }

    private function setRecordEnabled(int $recordId, int $userId, bool $enabled): void
    {
        $expression = $enabled ? 'flags | ?' : 'flags & ?';
        $mask = $enabled ? self::ENABLED : ~self::ENABLED;
        $query = $this->database->query(
            'UPDATE ' . $this->database->table_name('ident_switch')
            . " SET flags = {$expression} WHERE id = ? AND user_id = ? AND managed_externally = ?",
            $mask,
            $recordId,
            $userId,
            1,
        );
        if (!$query) {
            throw new RuntimeException('Unable to update managed account state');
        }
    }

    private function updateAssignment(
        string $assignmentId,
        int $principalId,
        string $status,
        ?int $recordId,
        ?int $identityId,
    ): void {
        $query = $this->database->query(
            'UPDATE ' . $this->database->table_name('sizestation_mailbox_assignments')
            . ' SET materialization_status = ?, ident_switch_record_id = ?, roundcube_identity_id = ?,'
            . ' updated_at = ?, last_error_code = NULL WHERE id = ? AND principal_id = ?',
            $status,
            $recordId,
            $identityId,
            gmdate('Y-m-d\TH:i:s\Z'),
            $assignmentId,
            $principalId,
        );
        if (!$query || $this->database->affected_rows($query) !== 1) {
            throw new RuntimeException('Unable to persist assignment materialization state');
        }
    }

    /** @param array<string, mixed> $assignment */
    private function identityName(array $assignment): string
    {
        $name = trim((string) ($assignment['display_label'] ?? ''));

        return substr($name !== '' ? $name : (string) $assignment['mailbox_address'], 0, 128);
    }

    /** @param array<string, mixed> $assignment */
    private function label(array $assignment): string
    {
        $label = trim((string) ($assignment['display_label'] ?? ''));

        return substr($label !== '' ? $label : (string) $assignment['mailbox_address'], 0, 32);
    }

    private function uniqueLabel(
        int $userId,
        string $desired,
        string $assignmentId,
        ?int $excludeRecordId = null,
    ): string {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $candidate = $desired;
            if ($attempt > 0) {
                $suffix = '~' . substr(hash('sha256', $assignmentId . ':' . $attempt), 0, 6);
                $candidate = substr($desired, 0, 32 - strlen($suffix)) . $suffix;
            }
            $query = $this->database->query(
                'SELECT id FROM ' . $this->database->table_name('ident_switch')
                . ' WHERE user_id = ? AND label = ?',
                $userId,
                $candidate,
            );
            $row = $this->database->fetch_assoc($query);
            if (!is_array($row) || (int) $row['id'] === $excludeRecordId) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to allocate a unique managed account label');
    }
}
