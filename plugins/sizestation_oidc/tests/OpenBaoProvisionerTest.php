<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Oidc;

use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Credentials\OpenBao\CredentialReference;
use SizeStation\Roundcube\Credentials\OpenBao\HttpResponse;
use SizeStation\Roundcube\Credentials\OpenBao\OpenBaoClientConfig;
use SizeStation\Roundcube\Oidc\Provisioning\OpenBaoAppRoleConfig;
use SizeStation\Roundcube\Oidc\Provisioning\OpenBaoKvV2Provisioner;
use SizeStation\Roundcube\Oidc\Provisioning\ProvisioningTransportInterface;

final class OpenBaoProvisionerTest extends TestCase
{
    public function testAppRoleLoginIsAutomaticAndCachedInMemory(): void
    {
        $transport = new class implements ProvisioningTransportInterface {
            /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
            public array $requests = [];

            public function request(
                string $method,
                string $url,
                array $headers,
                ?string $body,
                OpenBaoClientConfig $config,
            ): HttpResponse {
                $this->requests[] = compact('method', 'url', 'headers', 'body');

                return str_ends_with($url, '/v1/auth/approle/login')
                    ? new HttpResponse(200, '{"auth":{"client_token":"issued-token"}}')
                    : new HttpResponse(204, '');
            }
        };
        $config = new OpenBaoClientConfig(
            'https://openbao.example',
            '/unused-in-approle-mode',
            'kv',
            'roundcube/mailboxes',
            '/etc/ssl/certs/ca-certificates.crt',
        );
        $provisioner = new OpenBaoKvV2Provisioner(
            $config,
            $transport,
            new OpenBaoAppRoleConfig('role-id', 'secret-id'),
        );
        $reference = new CredentialReference('assignment/one');

        $provisioner->write($reference, ['username' => 'one@example.test', 'password' => 'first']);
        $provisioner->write($reference, ['username' => 'one@example.test', 'password' => 'second']);

        self::assertCount(3, $transport->requests);
        self::assertSame('https://openbao.example/v1/auth/approle/login', $transport->requests[0]['url']);
        self::assertSame(
            ['role_id' => 'role-id', 'secret_id' => 'secret-id'],
            json_decode((string) $transport->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertArrayNotHasKey('X-Vault-Token', $transport->requests[0]['headers']);
        self::assertSame('issued-token', $transport->requests[1]['headers']['X-Vault-Token']);
        self::assertSame('issued-token', $transport->requests[2]['headers']['X-Vault-Token']);
    }

    public function testCreateUsesKvV2CheckAndSetWhileRotationCreatesANewVersion(): void
    {
        $tokenFile = tempnam(sys_get_temp_dir(), 'bao-token-');
        self::assertIsString($tokenFile);
        file_put_contents($tokenFile, 'test-token');
        $transport = new class implements ProvisioningTransportInterface {
            /** @var list<string> */
            public array $bodies = [];

            public function request(
                string $method,
                string $url,
                array $headers,
                ?string $body,
                OpenBaoClientConfig $config,
            ): HttpResponse {
                $this->bodies[] = (string) $body;

                return new HttpResponse(204, '');
            }
        };
        try {
            $config = new OpenBaoClientConfig(
                'https://openbao.example',
                $tokenFile,
                'kv',
                'roundcube/mailboxes',
                $tokenFile,
            );
            $provisioner = new OpenBaoKvV2Provisioner($config, $transport);
            $reference = new CredentialReference('assignment/one');

            $provisioner->create($reference, ['username' => 'one@example.test', 'password' => 'secret']);
            $provisioner->write($reference, ['username' => 'one@example.test', 'password' => 'rotated']);

            $created = json_decode($transport->bodies[0], true, flags: JSON_THROW_ON_ERROR);
            $rotated = json_decode($transport->bodies[1], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(0, $created['options']['cas']);
            self::assertArrayNotHasKey('options', $rotated);
            self::assertSame('rotated', $rotated['data']['password']);
        } finally {
            unlink($tokenFile);
        }
    }
}
