<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Service;

use SizeStation\Roundcube\Oidc\Security\ValidatedIdentity;

final readonly class LoginPhase
{
    /** @param array<string, mixed> $principal
     *  @param array<string, mixed> $anchor
     *  @param list<array<string, mixed>> $assignments
     */
    public function __construct(
        public array $principal,
        public array $anchor,
        public array $assignments,
        public ValidatedIdentity $identity,
    ) {
    }
}
