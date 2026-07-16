<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Credentials;

use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Credentials\Exception\CredentialFailureKind;
use SizeStation\Roundcube\Credentials\Exception\ExternalCredentialException;
use SizeStation\Roundcube\Credentials\OpenBao\CredentialReference;
use SizeStation\Roundcube\Credentials\OpenBao\HttpResponse;
use SizeStation\Roundcube\Credentials\OpenBao\HttpTransportInterface;
use SizeStation\Roundcube\Credentials\OpenBao\OpenBaoClientConfig;
use SizeStation\Roundcube\Credentials\OpenBao\OpenBaoKvV2Client;

final class OpenBaoKvV2ClientTest extends TestCase
{
    private string $tokenFile;

    protected function setUp(): void
    {
        $this->tokenFile = tempnam(sys_get_temp_dir(), 'bao-token-');
        file_put_contents($this->tokenFile, 'initial-token');
    }

    protected function tearDown(): void
    {
        @unlink($this->tokenFile);
    }

    public function testParsesKvV2SecretWithoutReturningMetadata(): void
    {
        $transport = $this->transport([
            new HttpResponse(200, json_encode([
                'data' => [
                    'data' => ['username' => 'mailbox@example.test', 'password' => 'secret'],
                    'metadata' => ['version' => 3],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $secret = $this->client($transport)->read(new CredentialReference('assignment/1234'));

        self::assertSame([
            'username' => 'mailbox@example.test',
            'password' => 'secret',
        ], $secret);
    }

    public function testRereadsTokenAndRetriesExactlyOnceAfterForbidden(): void
    {
        $state = (object) ['tokens' => [], 'calls' => 0];
        $tokenFile = $this->tokenFile;
        $transport = new class ($state, $tokenFile) implements HttpTransportInterface {
            public function __construct(
                private readonly object $state,
                private readonly string $tokenFile,
            ) {
            }

            public function get(string $url, array $headers, OpenBaoClientConfig $config): HttpResponse
            {
                $this->state->tokens[] = $headers['X-Vault-Token'];
                ++$this->state->calls;
                if ($this->state->calls === 1) {
                    file_put_contents($this->tokenFile, 'rotated-token');

                    return new HttpResponse(403, '{}');
                }

                return new HttpResponse(200, '{"data":{"data":{"username":"u","password":"p"}}}');
            }
        };

        $this->client($transport)->read(new CredentialReference('assignment/1234'));

        self::assertSame(2, $state->calls);
        self::assertSame(['initial-token', 'rotated-token'], $state->tokens);
    }

    public function testStopsAfterSecondForbiddenResponse(): void
    {
        $transport = $this->transport([new HttpResponse(403, '{}'), new HttpResponse(403, '{}')]);

        try {
            $this->client($transport)->read(new CredentialReference('assignment/1234'));
            self::fail('Expected an external credential error');
        } catch (ExternalCredentialException $exception) {
            self::assertSame('openbao_forbidden', $exception->errorCode);
            self::assertSame(CredentialFailureKind::Unauthorized, $exception->kind);
            self::assertStringNotContainsString('initial-token', $exception->getMessage());
        }
    }

    public function testMapsMissingSecretToInvalidCredential(): void
    {
        $transport = $this->transport([new HttpResponse(404, '{"errors":["missing"]}')]);

        try {
            $this->client($transport)->read(new CredentialReference('assignment/missing'));
            self::fail('Expected an external credential error');
        } catch (ExternalCredentialException $exception) {
            self::assertSame('openbao_secret_missing', $exception->errorCode);
            self::assertSame(CredentialFailureKind::Invalid, $exception->kind);
            self::assertStringNotContainsString('missing', $exception->getMessage());
        }
    }

    public function testBuildsOnlyTheConfiguredKvV2Path(): void
    {
        $state = (object) ['url' => null];
        $transport = new class ($state) implements HttpTransportInterface {
            public function __construct(private readonly object $state)
            {
            }

            public function get(string $url, array $headers, OpenBaoClientConfig $config): HttpResponse
            {
                $this->state->url = $url;

                return new HttpResponse(200, '{"data":{"data":{"username":"u","password":"p"}}}');
            }
        };

        $this->client($transport)->read(new CredentialReference('user/assignment'));

        self::assertSame(
            'https://openbao:8200/v1/secret/data/roundcube/mailboxes/user/assignment',
            $state->url,
        );
    }

    /** @param list<HttpResponse> $responses */
    private function transport(array $responses): HttpTransportInterface
    {
        return new class ($responses) implements HttpTransportInterface {
            /** @param list<HttpResponse> $responses */
            public function __construct(private array $responses)
            {
            }

            public function get(string $url, array $headers, OpenBaoClientConfig $config): HttpResponse
            {
                return array_shift($this->responses) ?? new HttpResponse(500, '{}');
            }
        };
    }

    private function client(HttpTransportInterface $transport): OpenBaoKvV2Client
    {
        return new OpenBaoKvV2Client(new OpenBaoClientConfig(
            'https://openbao:8200',
            $this->tokenFile,
            'secret',
            'roundcube/mailboxes',
            '/test/ca.pem',
        ), $transport);
    }
}
