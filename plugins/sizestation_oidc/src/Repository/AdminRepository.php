<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Repository;

use RuntimeException;
use SizeStation\Roundcube\Oidc\Domain\MailboxAddress;
use SizeStation\Roundcube\Oidc\Domain\OpaqueId;

final class AdminRepository
{
    public function __construct(private readonly \rcube_db $database)
    {
    }

    /** @return array<string, mixed> */
    public function createAssignment(
        string $issuer,
        string $externalUserId,
        string $mailbox,
        string $credentialReference,
        ?string $label,
        bool $anchor,
        bool $preferred,
        string $actor,
        string $credentialStatus = 'unknown',
    ): array {
        $mailbox = (string) new MailboxAddress($mailbox);
        if (!in_array($credentialStatus, ['unknown', 'valid'], true)) {
            throw new RuntimeException('Initial credential status is invalid');
        }
        if ($issuer === '' || strlen($issuer) > 255 || $externalUserId === '' || strlen($externalUserId) > 255) {
            throw new RuntimeException('Issuer or external user identifier is invalid');
        }
        $id = (string) OpaqueId::generate();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $query = $this->database->query(
            'INSERT INTO ' . $this->database->table_name('sizestation_mailbox_assignments')
            . ' (id, issuer, external_user_id, mailbox_address, display_label, credential_provider,'
            . ' credential_reference, is_anchor, is_preferred, enabled, anchor_guard, preferred_guard,'
            . ' credential_status, created_by, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $id,
            $issuer,
            $externalUserId,
            $mailbox,
            $label,
            'openbao',
            $credentialReference,
            $anchor ? 1 : 0,
            $preferred ? 1 : 0,
            1,
            $anchor ? 'anchor' : null,
            $preferred ? 'preferred' : null,
            $credentialStatus,
            $actor,
            $now,
            $now,
        );
        if (!$query) {
            throw new RepositoryException('Unable to create mailbox assignment');
        }

        return $this->assignment($id) ?? throw new RepositoryException('Created assignment could not be loaded');
    }

    /** @return array<string, mixed>|null */
    public function assignment(string $assignmentId): ?array
    {
        new OpaqueId($assignmentId);
        $query = $this->database->query(
            'SELECT * FROM ' . $this->database->table_name('sizestation_mailbox_assignments') . ' WHERE id = ?',
            $assignmentId,
        );
        $row = $this->database->fetch_assoc($query);

        return is_array($row) ? $row : null;
    }

    public function markCredentialStatus(string $assignmentId, string $status, ?string $errorCode = null): void
    {
        if (!in_array($status, ['unknown', 'valid', 'invalid', 'unavailable'], true)) {
            throw new RuntimeException('Credential status is invalid');
        }
        $query = $this->database->query(
            'UPDATE ' . $this->database->table_name('sizestation_mailbox_assignments')
            . ' SET credential_status = ?, last_validated_at = ?, last_error_code = ?, updated_at = ? WHERE id = ?',
            $status,
            gmdate('Y-m-d\TH:i:s\Z'),
            $errorCode,
            gmdate('Y-m-d\TH:i:s\Z'),
            $assignmentId,
        );
        if (!$query || $this->database->affected_rows($query) !== 1) {
            throw new RepositoryException('Unable to update credential status');
        }
    }

    public function recordCredentialAvailabilityFailure(string $assignmentId, string $errorCode): void
    {
        $query = $this->database->query(
            'UPDATE ' . $this->database->table_name('sizestation_mailbox_assignments')
            . ' SET last_validated_at = ?, last_error_code = ?, updated_at = ? WHERE id = ?',
            gmdate('Y-m-d\TH:i:s\Z'),
            substr($errorCode, 0, 64),
            gmdate('Y-m-d\TH:i:s\Z'),
            $assignmentId,
        );
        if (!$query || $this->database->affected_rows($query) !== 1) {
            throw new RepositoryException('Unable to record credential availability failure');
        }
    }

    public function disableAssignment(string $assignmentId): array
    {
        $assignment = $this->requiredAssignment($assignmentId);
        if (!empty($assignment['is_anchor'])) {
            throw new RuntimeException('Anchor assignments cannot be disabled; disable the principal instead');
        }
        $query = $this->database->query(
            'UPDATE ' . $this->database->table_name('sizestation_mailbox_assignments')
            . ' SET enabled = 0, is_preferred = 0, preferred_guard = NULL, materialization_status = ?,'
            . ' updated_at = ? WHERE id = ?',
            'disabled',
            gmdate('Y-m-d\TH:i:s\Z'),
            $assignmentId,
        );
        if (!$query || $this->database->affected_rows($query) !== 1) {
            throw new RepositoryException('Unable to disable assignment');
        }

        return $this->requiredAssignment($assignmentId);
    }

    /** @return array<string, mixed> */
    public function enableAssignment(string $assignmentId): array
    {
        $assignment = $this->requiredAssignment($assignmentId);
        if (!empty($assignment['enabled'])) {
            return $assignment;
        }
        $query = $this->database->query(
            'UPDATE ' . $this->database->table_name('sizestation_mailbox_assignments')
            . ' SET enabled = 1, materialization_status = ?, updated_at = ? WHERE id = ? AND is_anchor = 0',
            'pending',
            gmdate('Y-m-d\TH:i:s\Z'),
            $assignmentId,
        );
        if (!$query || $this->database->affected_rows($query) !== 1) {
            throw new RepositoryException('Unable to enable assignment');
        }

        return $this->requiredAssignment($assignmentId);
    }

    public function removeAssignment(string $assignmentId): array
    {
        $assignment = $this->requiredAssignment($assignmentId);
        if (!empty($assignment['is_anchor'])) {
            throw new RuntimeException('Anchor assignments require an explicit anchor migration');
        }
        $query = $this->database->query(
            'UPDATE ' . $this->database->table_name('sizestation_mailbox_assignments')
            . ' SET enabled = 0, is_preferred = 0, preferred_guard = NULL, materialization_status = ?,'
            . ' credential_status = ?, updated_at = ? WHERE id = ?',
            'orphaned',
            'unavailable',
            gmdate('Y-m-d\TH:i:s\Z'),
            $assignmentId,
        );
        if (!$query || $this->database->affected_rows($query) !== 1) {
            throw new RepositoryException('Unable to retire assignment');
        }

        return $this->requiredAssignment($assignmentId);
    }

    public function deleteAssignmentForRollback(string $assignmentId): void
    {
        $query = $this->database->query(
            'DELETE FROM ' . $this->database->table_name('sizestation_mailbox_assignments')
            . ' WHERE id = ? AND principal_id IS NULL AND materialization_status = ?',
            $assignmentId,
            'pending',
        );
        if (!$query || $this->database->affected_rows($query) !== 1) {
            throw new RepositoryException('Unable to roll back pending assignment creation');
        }
    }

    public function setPreferred(string $assignmentId): array
    {
        $assignment = $this->requiredAssignment($assignmentId);
        if (empty($assignment['enabled'])) {
            throw new RuntimeException('A disabled assignment cannot be preferred');
        }
        if (!$this->database->startTransaction()) {
            throw new RepositoryException('Unable to start preferred assignment transaction');
        }
        try {
            $where = $assignment['principal_id'] === null
                ? 'issuer = ? AND external_user_id = ?'
                : 'principal_id = ?';
            $parameters = $assignment['principal_id'] === null
                ? [$assignment['issuer'], $assignment['external_user_id']]
                : [$assignment['principal_id']];
            $cleared = $this->database->query(
                'UPDATE ' . $this->database->table_name('sizestation_mailbox_assignments')
                . ' SET is_preferred = 0, preferred_guard = NULL WHERE ' . $where,
                ...$parameters,
            );
            $selected = $this->database->query(
                'UPDATE ' . $this->database->table_name('sizestation_mailbox_assignments')
                . ' SET is_preferred = 1, preferred_guard = ?, updated_at = ? WHERE id = ? AND enabled = 1',
                'preferred',
                gmdate('Y-m-d\TH:i:s\Z'),
                $assignmentId,
            );
            if (
                !$cleared || !$selected || $this->database->affected_rows($selected) !== 1
                || !$this->database->endTransaction()
            ) {
                throw new RepositoryException('Unable to set preferred assignment');
            }
        } catch (\Throwable $exception) {
            $this->database->rollbackTransaction();
            throw $exception;
        }

        return $this->requiredAssignment($assignmentId);
    }

    /** @return array<string, mixed> */
    public function clearPreferred(string $assignmentId): array
    {
        $assignment = $this->requiredAssignment($assignmentId);
        if (empty($assignment['is_preferred'])) {
            return $assignment;
        }
        $query = $this->database->query(
            'UPDATE ' . $this->database->table_name('sizestation_mailbox_assignments')
            . ' SET is_preferred = 0, preferred_guard = NULL, updated_at = ? WHERE id = ?',
            gmdate('Y-m-d\TH:i:s\Z'),
            $assignmentId,
        );
        if (!$query || $this->database->affected_rows($query) !== 1) {
            throw new RepositoryException('Unable to clear preferred assignment');
        }

        return $this->requiredAssignment($assignmentId);
    }

    /** @return array<string, mixed> */
    public function setAnchorBeforeInitialization(string $assignmentId): array
    {
        $assignment = $this->requiredAssignment($assignmentId);
        if (empty($assignment['enabled'])) {
            throw new RuntimeException('A disabled assignment cannot be the anchor');
        }
        if (!empty($assignment['is_anchor'])) {
            return $assignment;
        }
        if ($assignment['principal_id'] !== null) {
            $principals = $this->principals((int) $assignment['principal_id']);
            if ($principals === [] || $principals[0]['roundcube_user_id'] !== null) {
                throw new RuntimeException('An initialized anchor requires an explicit migration');
            }
        }
        if (!$this->database->startTransaction()) {
            throw new RepositoryException('Unable to start anchor selection transaction');
        }
        try {
            $where = $assignment['principal_id'] === null
                ? 'issuer = ? AND external_user_id = ?'
                : 'principal_id = ?';
            $parameters = $assignment['principal_id'] === null
                ? [$assignment['issuer'], $assignment['external_user_id']]
                : [$assignment['principal_id']];
            $cleared = $this->database->query(
                'UPDATE ' . $this->database->table_name('sizestation_mailbox_assignments')
                . ' SET is_anchor = 0, anchor_guard = NULL, materialization_status = ? WHERE '
                . $where . ' AND is_anchor = 1',
                'pending',
                ...$parameters,
            );
            $selected = $this->database->query(
                'UPDATE ' . $this->database->table_name('sizestation_mailbox_assignments')
                . ' SET is_anchor = 1, anchor_guard = ?, updated_at = ? WHERE id = ? AND enabled = 1',
                'anchor',
                gmdate('Y-m-d\TH:i:s\Z'),
                $assignmentId,
            );
            if (
                !$cleared || !$selected || $this->database->affected_rows($selected) !== 1
                || !$this->database->endTransaction()
            ) {
                throw new RepositoryException('Unable to select anchor assignment');
            }
        } catch (\Throwable $exception) {
            $this->database->rollbackTransaction();
            throw $exception;
        }

        return $this->requiredAssignment($assignmentId);
    }

    public function disablePrincipal(int $principalId): void
    {
        $query = $this->database->query(
            'UPDATE ' . $this->database->table_name('sizestation_oidc_principals')
            . ' SET status = ?, updated_at = ? WHERE id = ?',
            'disabled',
            gmdate('Y-m-d\TH:i:s\Z'),
            $principalId,
        );
        if (!$query || $this->database->affected_rows($query) !== 1) {
            throw new RepositoryException('Unable to disable principal');
        }
    }

    /** @return array<string, mixed> */
    public function enablePrincipal(int $principalId): array
    {
        $principals = $this->principals($principalId);
        if ($principals === []) {
            throw new RuntimeException('Principal was not found');
        }
        if ((string) $principals[0]['status'] !== 'disabled') {
            return $principals[0];
        }
        $query = $this->database->query(
            'UPDATE ' . $this->database->table_name('sizestation_oidc_principals')
            . " SET status = CASE WHEN roundcube_user_id IS NULL THEN 'pending' ELSE 'active' END,"
            . ' updated_at = ? WHERE id = ? AND status = ?',
            gmdate('Y-m-d\TH:i:s\Z'),
            $principalId,
            'disabled',
        );
        if (!$query || $this->database->affected_rows($query) !== 1) {
            throw new RepositoryException('Unable to enable principal');
        }

        return $this->principals($principalId)[0];
    }

    /** @return list<array<string, mixed>> */
    public function assignments(?int $principalId = null): array
    {
        $sql = 'SELECT * FROM ' . $this->database->table_name('sizestation_mailbox_assignments');
        $parameters = [];
        if ($principalId !== null) {
            $sql .= ' WHERE principal_id = ?';
            $parameters[] = $principalId;
        }
        $sql .= ' ORDER BY issuer, external_user_id, mailbox_address';
        $query = $this->database->query($sql, ...$parameters);
        $rows = [];
        while ($row = $this->database->fetch_assoc($query)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function principals(?int $principalId = null): array
    {
        $sql = 'SELECT * FROM ' . $this->database->table_name('sizestation_oidc_principals');
        $parameters = [];
        if ($principalId !== null) {
            $sql .= ' WHERE id = ?';
            $parameters[] = $principalId;
        }
        $sql .= ' ORDER BY id';
        $query = $this->database->query($sql, ...$parameters);
        $rows = [];
        while ($row = $this->database->fetch_assoc($query)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function audit(?int $principalId, int $limit): array
    {
        $limit = max(1, min(1000, $limit));
        $sql = 'SELECT * FROM ' . $this->database->table_name('sizestation_oidc_audit_log');
        $parameters = [];
        if ($principalId !== null) {
            $sql .= ' WHERE principal_id = ?';
            $parameters[] = $principalId;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        $query = $this->database->query($sql, ...$parameters);
        $rows = [];
        while ($row = $this->database->fetch_assoc($query)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function requiredAssignment(string $assignmentId): array
    {
        return $this->assignment($assignmentId) ?? throw new RuntimeException('Assignment was not found');
    }
}
