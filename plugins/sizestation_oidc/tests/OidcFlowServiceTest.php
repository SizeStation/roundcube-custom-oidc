<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Oidc;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SizeStation\Roundcube\Oidc\Oidc\OidcClientConfig;
use SizeStation\Roundcube\Oidc\Oidc\OidcFlowService;
use SizeStation\Roundcube\Oidc\Security\IdTokenValidator;
use SizeStation\Roundcube\Oidc\Security\TokenValidationConfig;

final class OidcFlowServiceTest extends TestCase
{
    private string $secretFile = '';
    private string $privateKey = '';
    /** @var array<string, mixed> */
    private array $jwks = [];

    protected function setUp(): void
    {
        $this->secretFile = tempnam(sys_get_temp_dir(), 'oidc-secret-');
        file_put_contents($this->secretFile, "client-secret\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        $details = openssl_pkey_get_details($key);
        $this->jwks = ['keys' => [[
            'kty' => 'RSA',
            'kid' => 'flow-key',
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => $this->encode($details['rsa']['n']),
            'e' => $this->encode($details['rsa']['e']),
        ]]];
    }

    protected function tearDown(): void
    {
        if ($this->secretFile !== '') {
            @unlink($this->secretFile);
        }
    }

    public function testBuildsAuthorizationCodeUrlWithPkce(): void
    {
        $transport = new FakeOidcTransport($this->metadata(), $this->jwks, '');
        $session = [];
        $url = $this->service($transport)->authorizationUrl($session);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('https://issuer.example/authorize', strtok($url, '?'));
        self::assertSame('code', $query['response_type']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('https://mail.example.test/oidc/callback', $query['redirect_uri']);
        self::assertNotEmpty($query['state']);
        self::assertNotEmpty($query['nonce']);
        self::assertNotEmpty($query['code_challenge']);
        self::assertArrayHasKey('sizestation_oidc.authorization', $session);
    }

    public function testCompletesFlowAndRejectsCallbackReplay(): void
    {
        $session = [];
        $transport = new FakeOidcTransport($this->metadata(), $this->jwks, '');
        $service = $this->service($transport);
        $url = $service->authorizationUrl($session);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $nonce = $session['sizestation_oidc.authorization']['nonce'];
        $transport->idToken = $this->token($nonce);

        $identity = $service->complete($session, $query['state'], 'authorization-code');
        self::assertSame('external-1', $identity->externalUserId);
        self::assertStringContainsString('client_secret=client-secret', $transport->tokenRequestBody);
        self::assertStringContainsString('code_verifier=', $transport->tokenRequestBody);

        $this->expectException(RuntimeException::class);
        $service->complete($session, $query['state'], 'authorization-code');
    }

    public function testRejectsDiscoveryIssuerMismatch(): void
    {
        $metadata = $this->metadata();
        $metadata['issuer'] = 'https://attacker.example/';
        $session = [];

        $this->expectException(RuntimeException::class);
        $this->service(new FakeOidcTransport($metadata, $this->jwks, ''))->authorizationUrl($session);
    }

    public function testRejectsDiscoveryThatAdvertisesPkceWithoutS256(): void
    {
        $metadata = $this->metadata();
        $metadata['code_challenge_methods_supported'] = ['plain'];
        $session = [];

        $this->expectException(RuntimeException::class);
        $this->service(new FakeOidcTransport($metadata, $this->jwks, ''))->authorizationUrl($session);
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        return [
            'issuer' => 'https://issuer.example/',
            'authorization_endpoint' => 'https://issuer.example/authorize',
            'token_endpoint' => 'https://issuer.example/token',
            'jwks_uri' => 'https://issuer.example/jwks',
            'end_session_endpoint' => 'https://issuer.example/logout',
            'code_challenge_methods_supported' => ['S256'],
        ];
    }

    private function service(FakeOidcTransport $transport): OidcFlowService
    {
        $config = new OidcClientConfig(
            'https://issuer.example/',
            'roundcube-client',
            $this->secretFile,
            'https://mail.example.test/oidc/callback',
        );

        return new OidcFlowService(
            $config,
            new IdTokenValidator(new TokenValidationConfig($config->issuer, $config->clientId)),
            http: $transport,
        );
    }

    private function token(string $nonce): string
    {
        $now = time();

        return JWT::encode([
            'iss' => 'https://issuer.example/',
            'sub' => 'subject-1',
            'aud' => 'roundcube-client',
            'exp' => $now + 300,
            'nbf' => $now - 10,
            'iat' => $now - 10,
            'nonce' => $nonce,
            'sizestation_user_id' => 'external-1',
        ], $this->privateKey, 'RS256', 'flow-key');
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
