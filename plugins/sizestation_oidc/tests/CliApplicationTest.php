<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Oidc;

use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Credentials\AccountCredentials;
use SizeStation\Roundcube\Oidc\Cli\Application;

#[RequiresPhpExtension('pdo_sqlite')]
final class CliApplicationTest extends TestCase
{
    private \rcube_db $database;
    private FakeSecretProvisioner $provisioner;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE system (name varchar(64) primary key, value text)');
        $pdo->exec(file_get_contents(__DIR__ . '/../SQL/sqlite.initial.sql'));
        $this->database = new \rcube_db($pdo);
        $this->provisioner = new FakeSecretProvisioner();
    }

    public function testProvisionReadsPasswordOnlyFromStdinAndNeverPrintsIt(): void
    {
        [$exit, $stdout, $stderr] = $this->runApplication([
            'sizestation-oidc',
            'provision',
            '--issuer',
            'https://issuer.example',
            '--external-user-id',
            'external-1',
            '--mailbox',
            'anchor@example.test',
            '--credential-reference',
            'mailboxes/anchor',
            '--anchor',
            '--preferred',
            '--password-stdin',
            '--json',
        ], "top-secret\n");

        self::assertSame(0, $exit);
        self::assertSame('', $stderr);
        self::assertStringNotContainsString('top-secret', $stdout);
        self::assertSame('top-secret', $this->provisioner->writes['mailboxes/anchor']['password']);
        self::assertSame(1, (int) $this->database->pdo->query(
            'SELECT COUNT(*) FROM sizestation_mailbox_assignments',
        )->fetchColumn());
    }

    public function testDryRunDoesNotWriteSecretOrDatabase(): void
    {
        [$exit, $stdout] = $this->runApplication([
            'sizestation-oidc',
            'provision',
            '--issuer=https://issuer.example',
            '--external-user-id=external-1',
            '--mailbox=anchor@example.test',
            '--credential-reference=mailboxes/anchor',
            '--anchor',
            '--password-stdin',
            '--dry-run',
            '--json',
        ], "top-secret\n");

        self::assertSame(0, $exit);
        self::assertStringContainsString('"dry_run":true', $stdout);
        self::assertSame([], $this->provisioner->writes);
        self::assertSame(0, (int) $this->database->pdo->query(
            'SELECT COUNT(*) FROM sizestation_mailbox_assignments',
        )->fetchColumn());
    }

    public function testPasswordCommandLineOptionIsRejectedWithoutEchoingValue(): void
    {
        [$exit, $stdout, $stderr] = $this->runApplication([
            'sizestation-oidc',
            'provision',
            '--password=must-not-appear',
            '--json',
        ], '');

        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertStringNotContainsString('must-not-appear', $stderr);
        self::assertStringContainsString('operation_rejected', $stderr);
    }

    public function testProvisionRejectsCredentialUsernameThatDiffersFromMailbox(): void
    {
        [$exit, $stdout, $stderr] = $this->runApplication([
            'sizestation-oidc',
            'provision',
            '--issuer=https://issuer.example',
            '--external-user-id=external-1',
            '--mailbox=anchor@example.test',
            '--username=other@example.test',
            '--credential-reference=mailboxes/anchor',
            '--anchor',
            '--password-stdin',
            '--json',
        ], "top-secret\n");

        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('operation_rejected', $stderr);
        self::assertSame([], $this->provisioner->writes);
    }

    public function testProvisioningFailureDeletesSecretAndRollsBackAssignment(): void
    {
        $this->provisioner->throwAfterWrite = true;
        [$exit, $stdout, $stderr] = $this->runApplication([
            'sizestation-oidc',
            'provision',
            '--issuer=https://issuer.example',
            '--external-user-id=external-1',
            '--mailbox=anchor@example.test',
            '--credential-reference=mailboxes/anchor',
            '--anchor',
            '--password-stdin',
            '--json',
        ], "top-secret\n");

        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('operation_failed', $stderr);
        self::assertSame(['mailboxes/anchor'], $this->provisioner->deletes);
        self::assertSame(0, (int) $this->database->pdo->query(
            'SELECT COUNT(*) FROM sizestation_mailbox_assignments',
        )->fetchColumn());
    }

    /** @param list<string> $arguments
     *  @return array{int, string, string}
     */
    private function runApplication(array $arguments, string $input): array
    {
        $stdin = fopen('php://memory', 'r+');
        fwrite($stdin, $input);
        rewind($stdin);
        $stdout = $stderr = '';
        $application = new Application(
            $this->database,
            $this->provisioner,
            static fn (array $assignment): AccountCredentials => new AccountCredentials(
                (string) $assignment['mailbox_address'],
                'resolved-secret',
            ),
        );
        $exit = $application->run(
            $arguments,
            $stdin,
            static function (string $value) use (&$stdout): void {
                $stdout .= $value;
            },
            static function (string $value) use (&$stderr): void {
                $stderr .= $value;
            },
        );
        fclose($stdin);

        return [$exit, $stdout, $stderr];
    }
}
