<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Security;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use RuntimeException;
use Throwable;

final class IdTokenValidator
{
    public function __construct(private readonly TokenValidationConfig $config)
    {
    }

    /** @param array<string, mixed> $jwks */
    public function validate(string $idToken, array $jwks, string $expectedNonce, int $now = 0): ValidatedIdentity
    {
        $algorithm = $this->algorithm($idToken);
        if (!in_array($algorithm, $this->config->allowedAlgorithms, true)) {
            throw new RuntimeException('ID token algorithm is not allowed');
        }

        $previousLeeway = JWT::$leeway;
        $previousTimestamp = JWT::$timestamp;
        JWT::$leeway = $this->config->clockToleranceSeconds;
        JWT::$timestamp = $now ?: null;
        try {
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($jwks));
        } catch (Throwable $exception) {
            throw new RuntimeException('ID token validation failed', 0, $exception);
        } finally {
            JWT::$leeway = $previousLeeway;
            JWT::$timestamp = $previousTimestamp;
        }

        $now = $now ?: time();
        $issuer = $this->requiredString($claims, 'iss');
        $subject = $this->requiredString($claims, 'sub');
        $externalUserId = $this->requiredString($claims, $this->config->externalUserIdClaim);
        $nonce = $this->requiredString($claims, 'nonce');
        if (!hash_equals($this->config->issuer, $issuer) || !hash_equals($expectedNonce, $nonce)) {
            throw new RuntimeException('ID token identity claims are invalid');
        }

        $audienceClaim = $claims['aud'] ?? null;
        $audiences = is_array($audienceClaim) ? $this->stringList($audienceClaim) : [$audienceClaim];
        if ($audiences === [] || in_array(null, $audiences, true)) {
            throw new RuntimeException('ID token audience is invalid');
        }
        if (!in_array($this->config->clientId, $audiences, true)) {
            throw new RuntimeException('ID token audience is invalid');
        }

        $authorizedParty = $claims['azp'] ?? null;
        if (
            (count($audiences) > 1 && !is_string($authorizedParty))
            || (is_string($authorizedParty) && !hash_equals($this->config->clientId, $authorizedParty))
        ) {
            throw new RuntimeException('ID token authorized party is invalid');
        }

        $issuedAt = $this->requiredInteger($claims, 'iat');
        $expiresAt = $this->requiredInteger($claims, 'exp');
        $notBefore = isset($claims['nbf']) ? $this->requiredInteger($claims, 'nbf') : $issuedAt;
        $tolerance = $this->config->clockToleranceSeconds;
        if ($issuedAt > $now + $tolerance || $notBefore > $now + $tolerance || $expiresAt < $now - $tolerance) {
            throw new RuntimeException('ID token time claims are invalid');
        }

        $groups = $this->stringList($claims[$this->config->groupsClaim] ?? []);
        if ($this->config->allowedGroups !== [] && array_intersect($groups, $this->config->allowedGroups) === []) {
            throw new RuntimeException('OIDC principal is not authorized for this application');
        }

        $authTime = isset($claims['auth_time']) ? $this->requiredInteger($claims, 'auth_time') : $issuedAt;
        if ($authTime > $now + $tolerance) {
            throw new RuntimeException('ID token authentication time is invalid');
        }

        return new ValidatedIdentity(
            $issuer,
            $subject,
            $externalUserId,
            $this->optionalString($claims, 'email'),
            ($claims['email_verified'] ?? false) === true,
            $this->optionalString($claims, 'preferred_username'),
            $this->optionalString($claims, 'name'),
            $authTime,
            $groups,
        );
    }

    private function algorithm(string $jwt): string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('ID token format is invalid');
        }

        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $header = is_string($decoded) ? json_decode($decoded, true) : null;
        if (!is_array($header) || !is_string($header['alg'] ?? null) || ($header['alg'] ?? null) === 'none') {
            throw new RuntimeException('ID token header is invalid');
        }

        return $header['alg'];
    }

    /** @param array<string, mixed> $claims */
    private function requiredString(array $claims, string $key): string
    {
        $value = $claims[$key] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > 255) {
            throw new RuntimeException('ID token required claim is missing');
        }

        return $value;
    }

    /** @param array<string, mixed> $claims */
    private function optionalString(array $claims, string $key): ?string
    {
        $value = $claims[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $claims */
    private function requiredInteger(array $claims, string $key): int
    {
        $value = $claims[$key] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new RuntimeException('ID token numeric claim is invalid');
        }

        return (int) $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
