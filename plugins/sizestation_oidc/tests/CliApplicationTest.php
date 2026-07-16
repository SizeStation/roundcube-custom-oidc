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
        self::assertStringContainsString('operation_rejected', $stderr);
        self::assertSame(['mailboxes/anchor'], $this->provisioner->deletes);
        self::assertSame(0, (int) $this->database->pdo->query(
            'SELECT COUNT(*) FROM sizestation_mailbox_assignments',
        )->fetchColumn());
    }

    public function testAdministrativeAssignmentLifecycleIsAuditedAndSecretSafe(): void
    {
        [, $anchorOutput] = $this->runApplication([
            'sizestation-oidc',
            'provision',
            '--issuer=https://issuer.example',
            '--external-user-id=external-1',
            '--mailbox=anchor@example.test',
            '--credential-reference=mailboxes/anchor',
            '--anchor',
            '--preferred',
            '--password-stdin',
            '--json',
        ], "anchor-secret\n");
        [, $secondaryOutput] = $this->runApplication([
            'sizestation-oidc',
            'provision',
            '--issuer=https://issuer.example',
            '--external-user-id=external-1',
            '--mailbox=secondary@example.test',
            '--credential-reference=mailboxes/secondary',
            '--password-stdin',
            '--json',
        ], "secondary-secret\n");
        $anchorId = (string) json_decode($anchorOutput, true, flags: JSON_THROW_ON_ERROR)['assignment_id'];
        $secondaryId = (string) json_decode($secondaryOutput, true, flags: JSON_THROW_ON_ERROR)['assignment_id'];

        [$rotateExit, $rotateOutput] = $this->runApplication([
            'sizestation-oidc',
            'rotate',
            '--assignment-id=' . $secondaryId,
            '--password-stdin',
            '--json',
        ], "rotated-secret\n");
        self::assertSame(0, $rotateExit);
        self::assertStringNotContainsString('rotated-secret', $rotateOutput);
        self::assertSame('rotated-secret', $this->provisioner->writes['mailboxes/secondary']['password']);

        [$validateExit, $validateOutput] = $this->runApplication([
            'sizestation-oidc',
            'validate',
            '--assignment-id=' . $secondaryId,
            '--json',
        ], '');
        self::assertSame(0, $validateExit);
        self::assertStringContainsString('"credential_status":"valid"', $validateOutput);

        [$preferredExit] = $this->runApplication([
            'sizestation-oidc',
            'set-preferred',
            '--assignment-id=' . $secondaryId,
            '--json',
        ], '');
        self::assertSame(0, $preferredExit);
        self::assertSame(1, (int) $this->database->pdo->query(
            "SELECT is_preferred FROM sizestation_mailbox_assignments WHERE id = '{$secondaryId}'",
        )->fetchColumn());
        self::assertSame(0, (int) $this->database->pdo->query(
            "SELECT is_preferred FROM sizestation_mailbox_assignments WHERE id = '{$anchorId}'",
        )->fetchColumn());

        [$disableExit] = $this->runApplication([
            'sizestation-oidc',
            'disable',
            '--assignment-id=' . $secondaryId,
            '--json',
        ], '');
        self::assertSame(0, $disableExit);
        self::assertSame('disabled', $this->database->pdo->query(
            "SELECT materialization_status FROM sizestation_mailbox_assignments WHERE id = '{$secondaryId}'",
        )->fetchColumn());

        [$removeExit, $removeOutput] = $this->runApplication([
            'sizestation-oidc',
            'remove',
            '--assignment-id=' . $secondaryId,
            '--delete-secret',
            '--json',
        ], '');
        self::assertSame(0, $removeExit);
        self::assertStringNotContainsString('secondary-secret', $removeOutput);
        self::assertContains('mailboxes/secondary', $this->provisioner->deletes);
        self::assertSame('orphaned', $this->database->pdo->query(
            "SELECT materialization_status FROM sizestation_mailbox_assignments WHERE id = '{$secondaryId}'",
        )->fetchColumn());

        $events = $this->database->pdo->query(
            'SELECT event_type FROM sizestation_oidc_audit_log ORDER BY id',
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('credential_rotated', $events);
        self::assertContains('credential_validation_success', $events);
        self::assertContains('preferred_account_changed', $events);
        self::assertContains('assignment_disabled', $events);
        self::assertContains('assignment_removed', $events);
    }

    public function testAnchorCannotBeDisabledOrRemovedByLifecycleCommands(): void
    {
        [, $output] = $this->runApplication([
            'sizestation-oidc',
            'provision',
            '--issuer=https://issuer.example',
            '--external-user-id=external-1',
            '--mailbox=anchor@example.test',
            '--credential-reference=mailboxes/anchor',
            '--anchor',
            '--password-stdin',
            '--json',
        ], "anchor-secret\n");
        $anchorId = (string) json_decode($output, true, flags: JSON_THROW_ON_ERROR)['assignment_id'];

        [$disableExit, , $disableError] = $this->runApplication([
            'sizestation-oidc',
            'disable',
            '--assignment-id=' . $anchorId,
            '--json',
        ], '');
        [$removeExit, , $removeError] = $this->runApplication([
            'sizestation-oidc',
            'remove',
            '--assignment-id=' . $anchorId,
            '--json',
        ], '');

        self::assertSame(1, $disableExit);
        self::assertSame(1, $removeExit);
        self::assertStringContainsString('operation_rejected', $disableError);
        self::assertStringContainsString('operation_rejected', $removeError);
        self::assertSame(1, (int) $this->database->pdo->query(
            "SELECT enabled FROM sizestation_mailbox_assignments WHERE id = '{$anchorId}'",
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
