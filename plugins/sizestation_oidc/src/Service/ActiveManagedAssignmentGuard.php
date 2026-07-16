<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Service;

final class ActiveManagedAssignmentGuard
{
    public function __construct(private readonly \rcube_db $database)
    {
    }

    public function mustReturnToAnchor(int $roundcubeUserId, int $activeIdentityId): bool
    {
        if ($roundcubeUserId < 1 || $activeIdentityId < 1) {
            return false;
        }
        $query = $this->database->query(
            'SELECT a.enabled, s.flags FROM ' . $this->database->table_name('ident_switch') . ' s'
            . ' INNER JOIN ' . $this->database->table_name('sizestation_mailbox_assignments') . ' a'
            . ' ON a.id = s.managed_assignment_id'
            . ' WHERE s.user_id = ? AND s.iid = ? AND s.managed_externally = 1',
            $roundcubeUserId,
            $activeIdentityId,
        );
        $row = $this->database->fetch_assoc($query);
        if (!is_array($row)) {
            return false;
        }

        return empty($row['enabled']) || (((int) $row['flags']) & 1) === 0;
    }
}
