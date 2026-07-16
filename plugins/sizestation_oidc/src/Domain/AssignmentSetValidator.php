<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Domain;

final class AssignmentSetValidator
{
    /** @param list<array<string, mixed>> $assignments */
    public function validateForLogin(array $assignments): void
    {
        $enabled = array_values(array_filter(
            $assignments,
            static fn (array $assignment): bool => !empty($assignment['enabled']),
        ));
        $anchors = array_values(array_filter(
            $enabled,
            static fn (array $assignment): bool => !empty($assignment['is_anchor']),
        ));
        $preferred = array_values(array_filter(
            $enabled,
            static fn (array $assignment): bool => !empty($assignment['is_preferred']),
        ));

        if (count($anchors) !== 1) {
            throw new AssignmentInvariantException('Exactly one enabled anchor assignment is required');
        }

        if (count($preferred) > 1) {
            throw new AssignmentInvariantException('At most one enabled preferred assignment is allowed');
        }
    }

    public function anchorGuard(bool $isAnchor, bool $enabled): ?string
    {
        return $isAnchor && $enabled ? 'anchor' : null;
    }

    public function preferredGuard(bool $isPreferred, bool $enabled): ?string
    {
        return $isPreferred && $enabled ? 'preferred' : null;
    }
}
