<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Repository;

use SizeStation\Roundcube\Oidc\Domain\AssignmentSetValidator;

final class AssignmentRepository
{
    public function __construct(
        private readonly \rcube_db $database,
        private readonly AssignmentSetValidator $validator = new AssignmentSetValidator(),
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function bindPending(int $principalId, string $issuer, string $externalUserId): array
    {
        if (!$this->database->startTransaction()) {
            throw new RepositoryException('Unable to start assignment binding');
        }

        try {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $query = $this->database->query(
                'UPDATE ' . $this->database->table_name('sizestation_mailbox_assignments')
                . ' SET principal_id = ?, bound_at = COALESCE(bound_at, ?), updated_at = ?'
                . ' WHERE issuer = ? AND external_user_id = ? AND principal_id IS NULL',
                $principalId,
                $now,
                $now,
                $issuer,
                $externalUserId,
            );
            if (!$query) {
                throw new RepositoryException('Unable to bind pending assignments');
            }

            $assignments = $this->forPrincipal($principalId, $issuer, $externalUserId);
            $this->validator->validateForLogin($assignments);

            if (!$this->database->endTransaction()) {
                throw new RepositoryException('Unable to commit assignment binding');
            }

            return $assignments;
        } catch (\Throwable $exception) {
            $this->database->rollbackTransaction();
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function forPrincipal(int $principalId, string $issuer, string $externalUserId): array
    {
        $query = $this->database->query(
            'SELECT * FROM ' . $this->database->table_name('sizestation_mailbox_assignments')
            . ' WHERE principal_id = ? AND issuer = ? AND external_user_id = ? ORDER BY mailbox_address',
            $principalId,
            $issuer,
            $externalUserId,
        );

        $assignments = [];
        while ($row = $this->database->fetch_assoc($query)) {
            $assignments[] = $row;
        }

        return $assignments;
    }

    /** @param list<array<string, mixed>> $assignments
     *  @return array<string, mixed>
     */
    public function anchor(array $assignments): array
    {
        $this->validator->validateForLogin($assignments);
        foreach ($assignments as $assignment) {
            if (!empty($assignment['enabled']) && !empty($assignment['is_anchor'])) {
                return $assignment;
            }
        }

        throw new RepositoryException('Anchor assignment could not be resolved');
    }

    /** @return array<string, mixed>|null */
    public function findOwnedEnabledAnchor(string $assignmentId, int $principalId): ?array
    {
        $query = $this->database->query(
            'SELECT * FROM ' . $this->database->table_name('sizestation_mailbox_assignments')
            . ' WHERE id = ? AND principal_id = ? AND enabled = ? AND is_anchor = ?',
            $assignmentId,
            $principalId,
            1,
            1,
        );
        $row = $this->database->fetch_assoc($query);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function preferred(array $assignments): ?array
    {
        foreach ($assignments as $assignment) {
            if (!empty($assignment['enabled']) && !empty($assignment['is_preferred'])) {
                return $assignment;
            }
        }

        return null;
    }

    public function markAnchorInitialized(string $assignmentId, int $principalId): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $query = $this->database->query(
            'UPDATE ' . $this->database->table_name('sizestation_mailbox_assignments')
            . ' SET materialization_status = ?, credential_status = ?, last_used_at = ?, updated_at = ?'
            . ' WHERE id = ? AND principal_id = ? AND enabled = ? AND is_anchor = ?',
            'anchor',
            'valid',
            $now,
            $now,
            $assignmentId,
            $principalId,
            1,
            1,
        );
        if (!$query || $this->database->affected_rows($query) !== 1) {
            throw new RepositoryException('Unable to initialize anchor assignment');
        }
    }

    public function markCredentialFailure(
        string $assignmentId,
        int $principalId,
        string $status,
        string $errorCode,
    ): void {
        if (!in_array($status, ['invalid', 'unavailable'], true)) {
            throw new RepositoryException('Unsupported credential failure status');
        }
        $query = $this->database->query(
            'UPDATE ' . $this->database->table_name('sizestation_mailbox_assignments')
            . ' SET credential_status = ?, last_validated_at = ?, last_error_code = ?, updated_at = ?'
            . ' WHERE id = ? AND principal_id = ? AND enabled = ? AND is_anchor = ?',
            $status,
            gmdate('Y-m-d\TH:i:s\Z'),
            substr($errorCode, 0, 64),
            gmdate('Y-m-d\TH:i:s\Z'),
            $assignmentId,
            $principalId,
            1,
            1,
        );
        if (!$query || $this->database->affected_rows($query) !== 1) {
            throw new RepositoryException('Unable to update anchor credential status');
        }
    }

    public function recordCredentialAvailabilityFailure(
        string $assignmentId,
        int $principalId,
        string $errorCode,
    ): void {
        $query = $this->database->query(
            'UPDATE ' . $this->database->table_name('sizestation_mailbox_assignments')
            . ' SET last_validated_at = ?, last_error_code = ?, updated_at = ?'
            . ' WHERE id = ? AND principal_id = ? AND enabled = ? AND is_anchor = ?',
            gmdate('Y-m-d\TH:i:s\Z'),
            substr($errorCode, 0, 64),
            gmdate('Y-m-d\TH:i:s\Z'),
            $assignmentId,
            $principalId,
            1,
            1,
        );
        if (!$query || $this->database->affected_rows($query) !== 1) {
            throw new RepositoryException('Unable to record anchor credential availability failure');
        }
    }
}
