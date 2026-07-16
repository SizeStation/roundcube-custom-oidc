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
}
