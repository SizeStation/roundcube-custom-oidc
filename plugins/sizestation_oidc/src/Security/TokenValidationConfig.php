<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Security;

final readonly class TokenValidationConfig
{
    /** @param list<string> $allowedAlgorithms
     *  @param list<string> $allowedGroups
     */
    public function __construct(
        public string $issuer,
        public string $clientId,
        public string $externalUserIdClaim = 'sizestation_user_id',
        public array $allowedAlgorithms = ['RS256'],
        public array $allowedGroups = [],
        public string $groupsClaim = 'groups',
        public int $clockToleranceSeconds = 60,
    ) {
    }
}
