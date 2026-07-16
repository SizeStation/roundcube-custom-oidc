<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Security;

final readonly class ValidatedIdentity
{
    /** @param list<string> $groups */
    public function __construct(
        public string $issuer,
        public string $subject,
        public string $externalUserId,
        public ?string $email,
        public bool $emailVerified,
        public ?string $preferredUsername,
        public ?string $displayName,
        public int $authenticationTime,
        public array $groups,
    ) {
    }
}
