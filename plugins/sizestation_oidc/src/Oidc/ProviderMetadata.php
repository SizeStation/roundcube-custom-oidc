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
            self::assertEndpoint($url);
        }
        if ($endSessionEndpoint !== null) {
            self::assertEndpoint($endSessionEndpoint);
        }
    }

    /** @param array<string, mixed> $document */
    public static function fromDocument(array $document, string $expectedIssuer): self
    {
        $issuer = self::requiredString($document, 'issuer');
        if (!hash_equals($expectedIssuer, $issuer)) {
            throw new RuntimeException('OIDC discovery issuer does not match configuration');
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

    private static function assertEndpoint(string $url): void
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
            throw new RuntimeException('OIDC discovery endpoint is not a safe HTTPS URL');
        }
    }
}
