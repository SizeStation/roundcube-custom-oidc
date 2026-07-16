<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Reconciliation;

final readonly class ReconciliationResult
{
    /** @param list<array{assignment_id: string, record_id: int, identity_id: int}> $materialized */
    public function __construct(
        public int $created,
        public int $updated,
        public int $disabled,
        public int $orphaned,
        public ?int $preferredSwitchRecordId,
        public array $materialized = [],
    ) {
    }
}
