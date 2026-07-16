<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Service;

use RuntimeException;
use SizeStation\Roundcube\Oidc\Reconciliation\AssignmentReconciler;
use SizeStation\Roundcube\Oidc\Reconciliation\ReconciliationResult;
use SizeStation\Roundcube\Oidc\Repository\AssignmentRepository;
use SizeStation\Roundcube\Oidc\Repository\PrincipalRepository;

final class LoginFinalizer
{
    private readonly PrincipalRepository $principals;
    private readonly AssignmentRepository $assignments;
    private readonly AssignmentReconciler $reconciler;

    public function __construct(
        private readonly \rcube_db $database,
        ?PrincipalRepository $principals = null,
        ?AssignmentRepository $assignments = null,
        ?AssignmentReconciler $reconciler = null,
    ) {
        $this->principals = $principals ?? new PrincipalRepository($database);
        $this->assignments = $assignments ?? new AssignmentRepository($database);
        $this->reconciler = $reconciler ?? new AssignmentReconciler($database);
    }

    /** @param list<array<string, mixed>> $assignments */
    public function finalize(
        int $principalId,
        int $roundcubeUserId,
        string $anchorAssignmentId,
        array $assignments,
    ): ReconciliationResult {
        if (!$this->database->startTransaction()) {
            throw new RuntimeException('Unable to start OIDC login finalization');
        }

        try {
            $this->principals->activate($principalId, $roundcubeUserId);
            $this->assignments->markAnchorInitialized($anchorAssignmentId, $principalId);
            $result = $this->reconciler->reconcile(
                $principalId,
                $roundcubeUserId,
                $assignments,
                false,
            );
            if (!$this->database->endTransaction()) {
                throw new RuntimeException('Unable to commit OIDC login finalization');
            }

            return $result;
        } catch (\Throwable $exception) {
            $this->database->rollbackTransaction();
            throw $exception;
        }
    }
}
