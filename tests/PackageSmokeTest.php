<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests;

use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Credentials\CredentialPurpose;

final class PackageSmokeTest extends TestCase
{
    public function testReleaseVersionMatchesDeploymentPins(): void
    {
        $root = dirname(__DIR__);
        $version = trim((string) file_get_contents($root . '/RELEASE_VERSION'));
        $stack = (string) file_get_contents($root . '/deployment/stack.yml');
        $launcher = (string) file_get_contents($root . '/bin/roundcube-oidc-admin');

        self::assertMatchesRegularExpression('/\A[0-9]+\.[0-9]+\.[0-9]+-rc\.[0-9]+\z/', $version);
        self::assertStringContainsString(
            'sizestation/roundcube-oidc-suite:' . $version . ',',
            $stack,
        );
        self::assertStringContainsString(
            'ROUNDCUBE_OIDC_ADMIN_VERSION:-' . $version,
            $launcher,
        );
        self::assertStringContainsString('default: ' . $version, $launcher);

        self::assertSame(1, preg_match('/-rc\.([0-9]+)\z/', $version, $releaseMatch));
        $plugin = (string) file_get_contents($root . '/plugins/ident_switch/ident_switch.php');
        $stylesheetName = 'ident_switch-rc' . $releaseMatch[1] . '.css';
        $stylesheet = $root . '/' . $stylesheetName;
        self::assertFileExists($stylesheet);
        self::assertStringContainsString("include_stylesheet('{$stylesheetName}')", $plugin);
        self::assertStringNotContainsString("include_stylesheet('plugins/", $plugin);
        self::assertStringContainsString('ident_switch-switch.js?v=' . $version, $plugin);
        self::assertStringContainsString(
            'plugins/ident_switch/ident_switch.css?v=' . $version,
            (string) file_get_contents($stylesheet),
        );
    }

    public function testSharedPackageIsAutoloadable(): void
    {
        self::assertSame('imap', CredentialPurpose::Imap->value);
    }

    public function testAccountSwitcherSupportsElasticFamilyWithoutLayoutShift(): void
    {
        $root = dirname(__DIR__) . '/plugins/ident_switch/';
        $script = (string) file_get_contents($root . 'ident_switch-switch.js');
        $stylesheet = (string) file_get_contents($root . 'ident_switch.css');

        self::assertStringContainsString("$('#layout-sidebar > .header-title.username')", $script);
        self::assertStringNotContainsString("rcmail.env.skin === 'elastic'", $script);
        self::assertStringContainsString('#layout-sidebar > .header-title.username', $stylesheet);
        self::assertStringContainsString('flex: 0 0 3.25rem', $stylesheet);
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
        self::assertSame(
            './bin/install-roundcube-oidc.php',
            $package['extra']['roundcube']['post-install-script'],
        );
        self::assertSame(
            './bin/install-roundcube-oidc.php',
            $package['extra']['roundcube']['post-update-script'],
        );
        self::assertSame(
            './bin/uninstall-roundcube-oidc.php',
            $package['extra']['roundcube']['post-uninstall-script'],
        );
        self::assertDirectoryExists(dirname(__DIR__) . '/SQL');
        self::assertFileExists(dirname(__DIR__) . '/roundcube_oidc_suite.php');
        self::assertFileExists(dirname(__DIR__) . '/config.runtime.php');
        self::assertFileIsReadable(dirname(__DIR__) . '/bin/install-roundcube-oidc.php');
        self::assertFileIsReadable(dirname(__DIR__) . '/bin/uninstall-roundcube-oidc.php');
        self::assertFileIsReadable(dirname(__DIR__) . '/bin/update-roundcube-oidc-db');
        self::assertFileIsReadable(dirname(__DIR__) . '/bin/update-roundcube-oidc-db.php');
        self::assertFileIsReadable(dirname(__DIR__) . '/bin/roundcube-oidc-admin');
        self::assertFileDoesNotExist(dirname(__DIR__) . '/bin/start-roundcube-oidc');
        self::assertFileDoesNotExist(dirname(__DIR__) . '/deployment/install-suite.sh');
        self::assertFileDoesNotExist(dirname(__DIR__) . '/deployment/roundcube-config.inc.php');
    }

    public function testPackageShipsFriendlyHostAdministrationLauncher(): void
    {
        $launcher = (string) file_get_contents(dirname(__DIR__) . '/bin/roundcube-oidc-admin');

        self::assertStringContainsString('provision SUB MAILBOX', $launcher);
        self::assertStringContainsString('add-email SUB MAILBOX', $launcher);
        self::assertStringContainsString('users)', $launcher);
        self::assertStringContainsString('emails)', $launcher);
        self::assertStringContainsString('Purelymail password:', $launcher);
        self::assertStringContainsString('ROUNDCUBE_OPENBAO_APPROLE_ID', $launcher);
        self::assertStringContainsString('ROUNDCUBE_OPENBAO_APPROLE_SECRET', $launcher);
        self::assertStringContainsString(
            'bin/../plugins/roundcube_oidc_suite/bin/sizestation-oidc',
            $launcher,
        );
        self::assertStringNotContainsString('client_secret=', $launcher);
    }

    public function testGeneratedLocalConfigIsAcceptedByRoundcube(): void
    {
        $config = null;
        require dirname(__DIR__) . '/config.inc.php.dist';

        self::assertSame([], $config);
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
