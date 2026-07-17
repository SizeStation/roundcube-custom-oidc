<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Oidc;

use InvalidArgumentException;

final readonly class OidcClientConfig
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $issuer,
        public string $clientId,
        public string $clientSecretFile,
        public string $redirectUri,
        public array $scopes = ['openid', 'profile', 'email'],
        public string $caFile = '',
        public int $connectTimeoutSeconds = 2,
        public int $requestTimeoutSeconds = 5,
    ) {
        $this->assertHttpsUrl($issuer, 'issuer');
        $this->assertHttpsUrl($redirectUri, 'redirect URI');
        if ($clientId === '' || $clientSecretFile === '') {
            throw new InvalidArgumentException('OIDC client ID and client-secret file are required');
        }
        if (!in_array('openid', $scopes, true) || $scopes === []) {
            throw new InvalidArgumentException('OIDC scopes must include openid');
        }
        foreach ($scopes as $scope) {
            if (!preg_match('/\A[A-Za-z0-9._:-]+\z/', $scope)) {
                throw new InvalidArgumentException('OIDC scope is invalid');
            }
        }
        if ($connectTimeoutSeconds < 1 || $requestTimeoutSeconds < $connectTimeoutSeconds) {
            throw new InvalidArgumentException('OIDC timeouts are invalid');
        }
    }

    public function discoveryUrl(): string
    {
        return rtrim($this->issuer, '/') . '/.well-known/openid-configuration';
    }

    private function assertHttpsUrl(string $url, string $label): void
    {
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException("OIDC {$label} must be an HTTPS URL");
        }
    }
}
