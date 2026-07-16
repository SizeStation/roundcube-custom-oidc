<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Oidc;

use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Credentials\OpenBao\CredentialReference;
use SizeStation\Roundcube\Credentials\OpenBao\HttpResponse;
use SizeStation\Roundcube\Credentials\OpenBao\OpenBaoClientConfig;
use SizeStation\Roundcube\Oidc\Provisioning\OpenBaoKvV2Provisioner;
use SizeStation\Roundcube\Oidc\Provisioning\ProvisioningTransportInterface;

final class OpenBaoProvisionerTest extends TestCase
{
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
