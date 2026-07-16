<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Security;

final readonly class OidcState
{
    public function __construct(
        public string $state,
        public string $nonce,
        public string $codeVerifier,
        public string $codeChallenge,
    ) {
    }
}
