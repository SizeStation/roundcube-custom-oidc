<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Oidc;

use Firebase\JWT\JWT;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SizeStation\Roundcube\Oidc\Oidc\OidcClientConfig;
use SizeStation\Roundcube\Oidc\Oidc\OidcFlowService;
use SizeStation\Roundcube\Oidc\Oidc\OidcHttpResponse;
use SizeStation\Roundcube\Oidc\Oidc\OidcHttpTransportInterface;
use SizeStation\Roundcube\Oidc\Repository\CallbackSecurityRepository;
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
        self::assertSame('openid profile email', $query['scope']);
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
        self::assertSame('subject-1', $identity->externalUserId);
        self::assertStringContainsString('client_secret=client-secret', $transport->tokenRequestBody);
        self::assertStringContainsString('code_verifier=', $transport->tokenRequestBody);

        $this->expectException(RuntimeException::class);
        $service->complete($session, $query['state'], 'authorization-code');
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testInvalidStateCannotConsumeTheSharedCallbackRateLimit(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE system (name varchar(64) primary key, value text)');
        $pdo->exec(file_get_contents(dirname(__DIR__, 3) . '/SQL/sqlite.initial.sql'));
        $database = new \rcube_db($pdo);
        $service = $this->service(
            new FakeOidcTransport($this->metadata(), $this->jwks, ''),
            new CallbackSecurityRepository($database),
        );

        for ($attempt = 0; $attempt < 21; $attempt++) {
            $session = [];
            try {
                $service->complete($session, 'attacker-state', 'attacker-code-' . $attempt, 'shared-proxy');
                self::fail('An invalid state must never reach the callback limiter');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('state', $exception->getMessage());
            }
        }

        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM sizestation_oidc_rate_limits',
        )->fetchColumn());
    }

    public function testRejectsDiscoveryIssuerMismatch(): void
    {
        $metadata = $this->metadata();
        $metadata['issuer'] = 'https://attacker.example/';
        $session = [];

        $this->expectException(RuntimeException::class);
        $this->service(new FakeOidcTransport($metadata, $this->jwks, ''))->authorizationUrl($session);
    }

    public function testRejectsCrossOriginDiscoveryEndpoints(): void
    {
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'end_session_endpoint'] as $key) {
            $metadata = $this->metadata();
            $metadata[$key] = 'https://attacker.example/internal-probe';
            $session = [];
            try {
                $this->service(new FakeOidcTransport($metadata, $this->jwks, ''))->authorizationUrl($session);
                self::fail("Cross-origin {$key} must be rejected");
            } catch (RuntimeException) {
                self::assertSame([], $session);
            }
        }
    }

    public function testRejectsDiscoveryThatAdvertisesPkceWithoutS256(): void
    {
        $metadata = $this->metadata();
        $metadata['code_challenge_methods_supported'] = ['plain'];
        $session = [];

        $this->expectException(RuntimeException::class);
        $this->service(new FakeOidcTransport($metadata, $this->jwks, ''))->authorizationUrl($session);
    }

    public function testBuildsEndSessionUrlWithFixedSameOriginRedirect(): void
    {
        $url = $this->service(new FakeOidcTransport($this->metadata(), $this->jwks, ''))
            ->endSessionUrl('https://mail.example.test/signed-out');
        self::assertIsString($url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('https://issuer.example/logout', strtok($url, '?'));
        self::assertSame('https://mail.example.test/signed-out', $query['post_logout_redirect_uri']);
        self::assertSame('roundcube-client', $query['client_id']);
    }

    public function testRejectsCrossOriginPostLogoutRedirect(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service(new FakeOidcTransport($this->metadata(), $this->jwks, ''))
            ->endSessionUrl('https://attacker.example/steal-session');
    }

    public function testFailsClosedWhenDiscoveryIsUnavailable(): void
    {
        $transport = $this->fixedResponseTransport(new OidcHttpResponse(503, 'temporarily unavailable'));
        $session = [];

        $this->expectException(RuntimeException::class);
        $this->service($transport)->authorizationUrl($session);
    }

    public function testFailsClosedForInvalidOrOversizedProviderJson(): void
    {
        foreach (['{invalid', str_repeat('x', 1048577)] as $body) {
            $session = [];
            try {
                $this->service($this->fixedResponseTransport(new OidcHttpResponse(200, $body)))
                    ->authorizationUrl($session);
                self::fail('Invalid provider data must be rejected');
            } catch (RuntimeException) {
                self::assertSame([], $session);
            }
        }
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

    private function service(
        OidcHttpTransportInterface $transport,
        ?CallbackSecurityRepository $callbackSecurity = null,
    ): OidcFlowService {
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
            callbackSecurity: $callbackSecurity,
        );
    }

    private function fixedResponseTransport(OidcHttpResponse $response): OidcHttpTransportInterface
    {
        return new class ($response) implements OidcHttpTransportInterface {
            public function __construct(private readonly OidcHttpResponse $response)
            {
            }

            public function request(
                string $method,
                string $url,
                array $headers,
                ?string $body,
                OidcClientConfig $config,
            ): OidcHttpResponse {
                return $this->response;
            }
        };
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
        ], $this->privateKey, 'RS256', 'flow-key');
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
