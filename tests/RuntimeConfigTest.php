<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests;

use PHPUnit\Framework\TestCase;

final class RuntimeConfigTest extends TestCase
{
    /** @var list<string> */
    private const VARIABLES = [
        'ROUNDCUBE_OIDC_ISSUER',
        'ROUNDCUBE_OIDC_ISSUER_FILE',
        'ROUNDCUBE_OIDC_CLIENT_ID',
        'ROUNDCUBE_OIDC_CLIENT_ID_FILE',
        'ROUNDCUBE_OIDC_CLIENT_SECRET_FILE',
        'ROUNDCUBE_OPENBAO_ADDRESS',
        'ROUNDCUBE_OPENBAO_ADDRESS_FILE',
        'ROUNDCUBE_OPENBAO_TOKEN_FILE',
    ];

    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $name) {
            putenv($name);
        }
    }

    public function testPackagedConfigReadsEnvironmentAndFileVariants(): void
    {
        $issuerFile = tempnam(sys_get_temp_dir(), 'oidc-issuer-');
        self::assertIsString($issuerFile);
        file_put_contents($issuerFile, "https://issuer.example/application/o/roundcube/\n");

        putenv('ROUNDCUBE_OIDC_ISSUER_FILE=' . $issuerFile);
        putenv('ROUNDCUBE_OIDC_CLIENT_ID=roundcube-client');
        putenv('ROUNDCUBE_OIDC_CLIENT_SECRET_FILE=/run/secrets/client-secret');
        putenv('ROUNDCUBE_OPENBAO_TOKEN_FILE=/run/secrets/openbao-token');

        try {
            $config = $this->loadConfig();
        } finally {
            unlink($issuerFile);
        }

        self::assertTrue($config['sizestation_oidc.enabled']);
        self::assertSame(
            'https://issuer.example/application/o/roundcube/',
            $config['sizestation_oidc.issuer'],
        );
        self::assertSame('roundcube-client', $config['sizestation_oidc.client_id']);
        self::assertSame('/run/secrets/client-secret', $config['sizestation_oidc.client_secret_file']);
        self::assertSame('/run/secrets/openbao-token', $config['sizestation_oidc.openbao_token_file']);
        self::assertSame(
            $config['sizestation_oidc.openbao_token_file'],
            $config['ident_switch.openbao_token_file'],
        );
    }

    public function testDirectAndFileVariantsFailClosedWhenBothAreSet(): void
    {
        $issuerFile = tempnam(sys_get_temp_dir(), 'oidc-issuer-');
        self::assertIsString($issuerFile);
        file_put_contents($issuerFile, 'https://issuer-from-file.example/');
        putenv('ROUNDCUBE_OIDC_ISSUER=https://issuer-from-env.example/');
        putenv('ROUNDCUBE_OIDC_ISSUER_FILE=' . $issuerFile);

        try {
            $this->expectException(\RuntimeException::class);
            $this->loadConfig();
        } finally {
            unlink($issuerFile);
        }
    }

    /** @return array<string, mixed> */
    private function loadConfig(): array
    {
        $config = [];
        require dirname(__DIR__) . '/config.runtime.php';

        return $config;
    }
}
