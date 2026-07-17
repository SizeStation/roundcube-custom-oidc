<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests;

use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Credentials\CredentialPurpose;

final class PackageSmokeTest extends TestCase
{
    public function testSharedPackageIsAutoloadable(): void
    {
        self::assertSame('imap', CredentialPurpose::Imap->value);
    }

    public function testPackageIsAStandardRoundcubePlugin(): void
    {
        $package = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('roundcube-plugin', $package['type']);
        self::assertArrayNotHasKey('sql-dir', $package['extra']['roundcube']);
        self::assertDirectoryExists(dirname(__DIR__) . '/SQL');
        self::assertFileExists(dirname(__DIR__) . '/roundcube_oidc_suite.php');
        self::assertFileExists(dirname(__DIR__) . '/config.runtime.php');
        self::assertFileIsReadable(dirname(__DIR__) . '/bin/start-roundcube-oidc');
        self::assertFileIsReadable(dirname(__DIR__) . '/bin/update-roundcube-oidc-db');
        self::assertFileDoesNotExist(dirname(__DIR__) . '/deployment/install-suite.sh');
        self::assertFileDoesNotExist(dirname(__DIR__) . '/deployment/roundcube-config.inc.php');
    }

    public function testPackageShipsAStandaloneAutoloader(): void
    {
        $root = dirname(__DIR__);
        $command = sprintf(
            '%s -n -r %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(
                'require ' . var_export($root . '/autoload.php', true) . ';'
                . 'exit(class_exists(' . var_export(
                    \SizeStation\Roundcube\Credentials\Provider\DatabaseCredentialProvider::class,
                    true,
                ) . ') ? 0 : 1);',
            ),
        );

        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
    }
}
