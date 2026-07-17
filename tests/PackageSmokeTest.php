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
        self::assertSame('SQL', $package['extra']['roundcube']['sql-dir']);
        self::assertFileExists(dirname(__DIR__) . '/roundcube_oidc_suite.php');
        self::assertFileExists(dirname(__DIR__) . '/config.runtime.php');
        self::assertFileDoesNotExist(dirname(__DIR__) . '/deployment/install-suite.sh');
        self::assertFileDoesNotExist(dirname(__DIR__) . '/deployment/roundcube-config.inc.php');
    }
}
