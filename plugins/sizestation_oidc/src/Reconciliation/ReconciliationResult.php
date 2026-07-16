<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Reconciliation;

final readonly class ReconciliationResult
{
    public function __construct(
        public int $created,
        public int $updated,
        public int $disabled,
        public int $orphaned,
        public ?int $preferredSwitchRecordId,
    ) {
    }
}
