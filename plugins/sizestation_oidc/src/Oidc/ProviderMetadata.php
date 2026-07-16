<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Oidc;

use RuntimeException;

final readonly class ProviderMetadata
{
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
        public ?string $endSessionEndpoint,
    ) {
        foreach ([$authorizationEndpoint, $tokenEndpoint, $jwksUri] as $url) {
            self::assertEndpoint($url, $issuer);
        }
        if ($endSessionEndpoint !== null) {
            self::assertEndpoint($endSessionEndpoint, $issuer);
        }
    }

    /** @param array<string, mixed> $document */
    public static function fromDocument(array $document, string $expectedIssuer): self
    {
        $issuer = self::requiredString($document, 'issuer');
        if (!hash_equals($expectedIssuer, $issuer)) {
            throw new RuntimeException('OIDC discovery issuer does not match configuration');
        }
        $pkceMethods = $document['code_challenge_methods_supported'] ?? null;
        if (is_array($pkceMethods) && !in_array('S256', $pkceMethods, true)) {
            throw new RuntimeException('OIDC provider does not advertise PKCE S256 support');
        }

        return new self(
            $issuer,
            self::requiredString($document, 'authorization_endpoint'),
            self::requiredString($document, 'token_endpoint'),
            self::requiredString($document, 'jwks_uri'),
            self::optionalString($document, 'end_session_endpoint'),
        );
    }

    /** @param array<string, mixed> $document */
    private static function requiredString(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('OIDC discovery document is incomplete');
        }

        return $value;
    }

    /** @param array<string, mixed> $document */
    private static function optionalString(array $document, string $key): ?string
    {
        $value = $document[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function assertEndpoint(string $url, string $issuer): void
    {
        $parts = parse_url($url);
        $issuerParts = parse_url($issuer);
        if (
            !is_array($parts)
            || !is_array($issuerParts)
            || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
            || ($issuerParts['scheme'] ?? null) !== 'https'
            || empty($issuerParts['host'])
            || !hash_equals(strtolower((string) $issuerParts['host']), strtolower((string) $parts['host']))
            || (int) ($issuerParts['port'] ?? 443) !== (int) ($parts['port'] ?? 443)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new RuntimeException('OIDC discovery endpoint is not on the configured HTTPS origin');
        }
    }
}
