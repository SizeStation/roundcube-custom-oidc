<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Oidc;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SizeStation\Roundcube\Oidc\Security\IdTokenValidator;
use SizeStation\Roundcube\Oidc\Security\TokenValidationConfig;

final class IdTokenValidatorTest extends TestCase
{
    private string $privateKey = '';
    /** @var array<string, mixed> */
    private array $jwks;

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        $details = openssl_pkey_get_details($key);
        $this->jwks = ['keys' => [[
            'kty' => 'RSA',
            'kid' => 'test-key',
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => $this->encode($details['rsa']['n']),
            'e' => $this->encode($details['rsa']['e']),
        ]]];
    }

    public function testValidatesCanonicalIdentityAudienceNonceAndGroups(): void
    {
        $now = time();
        $identity = $this->validator(['mail-users'])->validate(
            $this->token(['groups' => ['mail-users']], $now),
            $this->jwks,
            'expected-nonce',
            $now,
        );

        self::assertSame('https://issuer.example/application/o/roundcube/', $identity->issuer);
        self::assertSame('subject-1', $identity->subject);
        self::assertSame('subject-1', $identity->externalUserId);
        self::assertSame(['mail-users'], $identity->groups);
    }

    public function testRejectsWrongIssuer(): void
    {
        $now = time();
        $this->expectException(RuntimeException::class);
        $this->validator()->validate(
            $this->token(['iss' => 'https://attacker.example/'], $now),
            $this->jwks,
            'expected-nonce',
            $now,
        );
    }

    public function testRejectsWrongAudience(): void
    {
        $now = time();
        $this->expectException(RuntimeException::class);
        $this->validator()->validate(
            $this->token(['aud' => 'other-client'], $now),
            $this->jwks,
            'expected-nonce',
            $now,
        );
    }

    public function testRejectsMismatchedNonce(): void
    {
        $now = time();
        $this->expectException(RuntimeException::class);
        $this->validator()->validate($this->token([], $now), $this->jwks, 'other-nonce', $now);
    }

    public function testRejectsUnauthorizedGroup(): void
    {
        $now = time();
        $this->expectException(RuntimeException::class);
        $this->validator(['required-group'])->validate(
            $this->token(['groups' => ['other-group']], $now),
            $this->jwks,
            'expected-nonce',
            $now,
        );
    }

    public function testRejectsExpiredToken(): void
    {
        $now = time();
        $this->expectException(RuntimeException::class);
        $this->validator()->validate(
            $this->token(['exp' => $now - 61], $now),
            $this->jwks,
            'expected-nonce',
            $now,
        );
    }

    public function testRejectsMissingNonce(): void
    {
        $now = time();
        $this->expectException(RuntimeException::class);
        $this->validator()->validate(
            $this->token(['nonce' => null], $now),
            $this->jwks,
            'expected-nonce',
            $now,
        );
    }

    public function testRejectsMultipleAudiencesWithoutAuthorizedParty(): void
    {
        $now = time();
        $this->expectException(RuntimeException::class);
        $this->validator()->validate(
            $this->token(['aud' => ['roundcube-client', 'other-client']], $now),
            $this->jwks,
            'expected-nonce',
            $now,
        );
    }

    public function testRejectsInvalidSignature(): void
    {
        $now = time();
        $otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($otherKey, $otherPrivateKey);
        $claims = [
            'iss' => 'https://issuer.example/application/o/roundcube/',
            'sub' => 'subject-1',
            'aud' => 'roundcube-client',
            'exp' => $now + 300,
            'iat' => $now,
            'nonce' => 'expected-nonce',
        ];

        $this->expectException(RuntimeException::class);
        $this->validator()->validate(
            JWT::encode($claims, $otherPrivateKey, 'RS256', 'test-key'),
            $this->jwks,
            'expected-nonce',
            $now,
        );
    }

    public function testSupportsConfiguredCustomExternalIdentityClaim(): void
    {
        $now = time();
        $validator = new IdTokenValidator(new TokenValidationConfig(
            'https://issuer.example/application/o/roundcube/',
            'roundcube-client',
            externalUserIdClaim: 'custom_identity',
        ));

        $identity = $validator->validate(
            $this->token(['custom_identity' => 'external-1'], $now),
            $this->jwks,
            'expected-nonce',
            $now,
        );

        self::assertSame('external-1', $identity->externalUserId);
    }

    /** @param list<string> $groups */
    private function validator(array $groups = []): IdTokenValidator
    {
        return new IdTokenValidator(new TokenValidationConfig(
            'https://issuer.example/application/o/roundcube/',
            'roundcube-client',
            allowedGroups: $groups,
        ));
    }

    /** @param array<string, mixed> $overrides */
    private function token(array $overrides, int $now): string
    {
        $claims = array_replace([
            'iss' => 'https://issuer.example/application/o/roundcube/',
            'sub' => 'subject-1',
            'aud' => 'roundcube-client',
            'exp' => $now + 300,
            'nbf' => $now - 10,
            'iat' => $now - 10,
            'auth_time' => $now - 20,
            'nonce' => 'expected-nonce',
            'email' => 'user@example.test',
            'email_verified' => true,
        ], $overrides);

        return JWT::encode($claims, $this->privateKey, 'RS256', 'test-key');
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
