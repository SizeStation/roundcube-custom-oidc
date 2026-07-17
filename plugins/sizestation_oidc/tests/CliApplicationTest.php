<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Oidc;

use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Credentials\AccountCredentials;
use SizeStation\Roundcube\Credentials\Exception\CredentialFailureKind;
use SizeStation\Roundcube\Credentials\Exception\ExternalCredentialException;
use SizeStation\Roundcube\Oidc\Cli\Application;
use SizeStation\Roundcube\Oidc\Provisioning\MailboxCredentialValidator;
use SizeStation\Roundcube\Oidc\Repository\AdminRepository;
use SizeStation\Roundcube\Oidc\Repository\PrincipalRepository;

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
        $pdo->exec('CREATE TABLE users (user_id integer PRIMARY KEY, username text)');
        $pdo->exec('CREATE TABLE identities ('
            . 'identity_id integer PRIMARY KEY, user_id integer NOT NULL, changed text NOT NULL,'
            . 'del integer NOT NULL DEFAULT 0, standard integer NOT NULL DEFAULT 0,'
            . 'name varchar(128) NOT NULL DEFAULT \'\', email varchar(128) NOT NULL DEFAULT \'\','
            . 'signature text NOT NULL DEFAULT \'\', html_signature integer NOT NULL DEFAULT 0)');
        $pdo->exec(file_get_contents(dirname(__DIR__, 3) . '/SQL/sqlite.initial.sql'));
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

    public function testAssignmentCreateCanReferenceAnExistingSecretWithoutMutatingIt(): void
    {
        [$exit, $stdout, $stderr] = $this->runApplication([
            'sizestation-oidc',
            'assignment:create',
            '--issuer=https://issuer.example',
            '--external-user-id=external-1',
            '--mailbox=anchor@example.test',
            '--credential-reference=mailboxes/existing',
            '--anchor',
            '--json',
        ], '');

        self::assertSame(0, $exit);
        self::assertSame('', $stderr);
        self::assertStringNotContainsString('resolved-secret', $stdout);
        self::assertSame([], $this->provisioner->writes);
        self::assertSame([], $this->provisioner->deletes);
        self::assertSame('mailboxes/existing', $this->database->pdo->query(
            'SELECT credential_reference FROM sizestation_mailbox_assignments',
        )->fetchColumn());
    }

    public function testFirstMailboxDefaultsToAnchorAndPreferredWhileLaterMailboxesDoNot(): void
    {
        [$firstExit, $firstOutput] = $this->runApplication([
            'sizestation-oidc', 'assignment:create', '--issuer=https://issuer.example',
            '--external-user-id=external-1', '--mailbox=first@example.test',
            '--credential-reference=mailboxes/first', '--password-stdin', '--json',
        ], "first-secret\n");
        [$secondExit, $secondOutput] = $this->runApplication([
            'sizestation-oidc', 'assignment:create', '--issuer=https://issuer.example',
            '--external-user-id=external-1', '--mailbox=second@example.test',
            '--credential-reference=mailboxes/second', '--password-stdin', '--json',
        ], "second-secret\n");

        self::assertSame(0, $firstExit);
        self::assertSame(0, $secondExit);
        $first = json_decode($firstOutput, true, flags: JSON_THROW_ON_ERROR);
        $second = json_decode($secondOutput, true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($first['first_mailbox']);
        self::assertTrue($first['anchor']);
        self::assertTrue($first['preferred']);
        self::assertFalse($second['first_mailbox']);
        self::assertFalse($second['anchor']);
        self::assertFalse($second['preferred']);
    }

    public function testAssignmentCreateReusesAProvisionedMailboxCredentialAcrossPrincipals(): void
    {
        [$firstExit] = $this->runApplication([
            'sizestation-oidc',
            'assignment:create',
            '--issuer=https://issuer.example',
            '--external-user-id=external-1',
            '--mailbox=contact@example.test',
            '--credential-reference=mailboxes/contact-original',
            '--anchor',
            '--password-stdin',
            '--json',
        ], "first-secret\n");
        [$secondExit, $secondOutput, $secondError] = $this->runApplication([
            'sizestation-oidc',
            'assignment:create',
            '--issuer=https://issuer.example',
            '--external-user-id=external-2',
            '--mailbox=contact@example.test',
            '--reuse-existing',
            '--anchor',
            '--json',
        ], '');

        self::assertSame(0, $firstExit);
        self::assertSame(0, $secondExit);
        self::assertSame('', $secondError);
        $result = json_decode($secondOutput, true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($result['credential_reused']);
        self::assertSame('mailboxes/contact-original', $result['credential_reference']);
        self::assertSame(2, (int) $this->database->pdo->query(
            "SELECT COUNT(*) FROM sizestation_mailbox_assignments"
            . " WHERE credential_reference = 'mailboxes/contact-original'",
        )->fetchColumn());
        self::assertCount(1, $this->provisioner->writes);
    }

    public function testReuseExistingReportsAStableErrorWhenMailboxWasNeverProvisioned(): void
    {
        [$exit, $stdout, $stderr] = $this->runApplication([
            'sizestation-oidc',
            'assignment:create',
            '--issuer=https://issuer.example',
            '--external-user-id=external-1',
            '--mailbox=missing@example.test',
            '--reuse-existing',
            '--anchor',
            '--json',
        ], '');

        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('reusable_credential_not_found', $stderr);
        self::assertSame([], $this->provisioner->writes);
    }

    public function testCredentialReferenceCannotBeReusedForAnotherMailbox(): void
    {
        [$firstExit] = $this->runApplication([
            'sizestation-oidc', 'assignment:create', '--issuer=https://issuer.example',
            '--external-user-id=external-1', '--mailbox=one@example.test',
            '--credential-reference=mailboxes/shared', '--anchor', '--password-stdin', '--json',
        ], "first-secret\n");
        [$secondExit, , $secondError] = $this->runApplication([
            'sizestation-oidc', 'assignment:create', '--issuer=https://issuer.example',
            '--external-user-id=external-2', '--mailbox=two@example.test',
            '--credential-reference=mailboxes/shared', '--anchor', '--json',
        ], '');

        self::assertSame(0, $firstExit);
        self::assertSame(1, $secondExit);
        self::assertStringContainsString('operation_rejected', $secondError);
        self::assertSame(1, (int) $this->database->pdo->query(
            'SELECT COUNT(*) FROM sizestation_mailbox_assignments',
        )->fetchColumn());
    }

    public function testExistingSecretUsernameMustMatchTheMailbox(): void
    {
        [$exit, $stdout, $stderr] = $this->runApplication([
            'sizestation-oidc',
            'assignment:create',
            '--issuer=https://issuer.example',
            '--external-user-id=external-1',
            '--mailbox=anchor@example.test',
            '--credential-reference=mailboxes/existing',
            '--anchor',
            '--json',
        ], '', static fn (): AccountCredentials => new AccountCredentials(
            'other@example.test',
            'resolved-secret',
        ));

        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('operation_rejected', $stderr);
        self::assertStringNotContainsString('resolved-secret', $stderr);
        self::assertSame([], $this->provisioner->writes);
        self::assertSame([], $this->provisioner->deletes);
        self::assertSame(0, (int) $this->database->pdo->query(
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
        $plan = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['mailboxes/anchor'], $plan['openbao_paths']);
        self::assertContains('create pending mailbox assignment', $plan['database_changes']);
        self::assertSame([], $plan['ident_switch_record_ids']);
        self::assertArrayHasKey('validation_actions', $plan);
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

    public function testCanonicalReconcileAndAuditCommandsSupportStructuredOutput(): void
    {
        [$reconcileExit, $reconcileOutput] = $this->runApplication([
            'sizestation-oidc',
            'reconcile:all',
            '--format=json',
        ], '');
        [$auditExit, $auditOutput] = $this->runApplication([
            'sizestation-oidc',
            'audit:list',
            '--format',
            'json',
        ], '');

        self::assertSame(0, $reconcileExit);
        self::assertSame([], json_decode($reconcileOutput, true, flags: JSON_THROW_ON_ERROR)['results']);
        self::assertSame(0, $auditExit);
        self::assertSame([], json_decode($auditOutput, true, flags: JSON_THROW_ON_ERROR)['events']);
    }

    public function testInvalidOutputFormatIsRejected(): void
    {
        [$exit, $stdout, $stderr] = $this->runApplication([
            'sizestation-oidc',
            'principal:list',
            '--format=yaml',
        ], '');

        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
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

    public function testAmbiguousProvisioningFailureDoesNotDeletePossiblyPreexistingSecret(): void
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
        self::assertSame([], $this->provisioner->deletes);
        self::assertSame(0, (int) $this->database->pdo->query(
            'SELECT COUNT(*) FROM sizestation_mailbox_assignments',
        )->fetchColumn());
    }

    public function testDatabaseFailureAfterSecretCreationDeletesTheNewSecret(): void
    {
        [$firstExit] = $this->runApplication([
            'sizestation-oidc',
            'provision',
            '--issuer=https://issuer.example',
            '--external-user-id=external-1',
            '--mailbox=anchor@example.test',
            '--credential-reference=mailboxes/anchor',
            '--anchor',
            '--password-stdin',
            '--json',
        ], "first-secret\n");
        [$secondExit, , $secondError] = $this->runApplication([
            'sizestation-oidc',
            'provision',
            '--issuer=https://issuer.example',
            '--external-user-id=external-1',
            '--mailbox=anchor@example.test',
            '--credential-reference=mailboxes/new-orphan',
            '--password-stdin',
            '--json',
        ], "second-secret\n");

        self::assertSame(0, $firstExit);
        self::assertSame(1, $secondExit);
        self::assertStringContainsString('operation_rejected', $secondError);
        self::assertContains('mailboxes/new-orphan', $this->provisioner->deletes);
        self::assertSame(1, (int) $this->database->pdo->query(
            'SELECT COUNT(*) FROM sizestation_mailbox_assignments',
        )->fetchColumn());
    }

    public function testCreateOnlyProvisioningCannotOverwriteAnAssignedCredentialReference(): void
    {
        [$firstExit] = $this->runApplication([
            'sizestation-oidc',
            'provision',
            '--issuer=https://issuer.example',
            '--external-user-id=external-1',
            '--mailbox=anchor@example.test',
            '--credential-reference=mailboxes/shared',
            '--anchor',
            '--password-stdin',
            '--json',
        ], "first-secret\n");
        [$secondExit] = $this->runApplication([
            'sizestation-oidc',
            'provision',
            '--issuer=https://issuer.example',
            '--external-user-id=external-2',
            '--mailbox=other@example.test',
            '--credential-reference=mailboxes/shared',
            '--anchor',
            '--password-stdin',
            '--json',
        ], "must-not-overwrite\n");

        self::assertSame(0, $firstExit);
        self::assertSame(1, $secondExit);
        self::assertSame('first-secret', $this->provisioner->writes['mailboxes/shared']['password']);
        self::assertSame([], $this->provisioner->deletes);
    }

    public function testPostLoginAssignmentIsBoundAndMaterializedImmediately(): void
    {
        $principal = (new PrincipalRepository($this->database))->resolveOrCreate(
            'https://issuer.example',
            'subject-1',
            'external-1',
        );
        $principalId = (int) $principal['id'];
        $this->database->pdo->exec("INSERT INTO users (user_id, username) VALUES (10, 'anchor@example.test')");
        $this->database->pdo->exec(
            "UPDATE sizestation_oidc_principals SET status = 'active', roundcube_user_id = 10"
            . " WHERE id = {$principalId}",
        );

        [$anchorExit] = $this->runApplication([
            'sizestation-oidc', 'assignment:create', '--issuer=https://issuer.example',
            '--external-user-id=external-1', '--mailbox=anchor@example.test',
            '--credential-reference=mailboxes/anchor', '--anchor', '--password-stdin', '--json',
        ], "anchor-secret\n");
        [$secondaryExit, $secondaryOutput] = $this->runApplication([
            'sizestation-oidc', 'assignment:create', '--issuer=https://issuer.example',
            '--external-user-id=external-1', '--mailbox=secondary@example.test',
            '--credential-reference=mailboxes/secondary', '--password-stdin', '--json',
        ], "secondary-secret\n");
        $secondaryId = (string) json_decode(
            $secondaryOutput,
            true,
            flags: JSON_THROW_ON_ERROR,
        )['assignment_id'];

        self::assertSame(0, $anchorExit);
        self::assertSame(0, $secondaryExit);
        self::assertSame($principalId, (int) $this->database->pdo->query(
            "SELECT principal_id FROM sizestation_mailbox_assignments WHERE id = '{$secondaryId}'",
        )->fetchColumn());
        self::assertSame(1, (int) $this->database->pdo->query(
            "SELECT COUNT(*) FROM ident_switch WHERE managed_assignment_id = '{$secondaryId}'",
        )->fetchColumn());
    }

    public function testSourceDisableImmediatelyDisablesMaterializedSwitchRecord(): void
    {
        $principal = (new PrincipalRepository($this->database))->resolveOrCreate(
            'https://issuer.example',
            'subject-1',
            'external-1',
        );
        $principalId = (int) $principal['id'];
        $this->database->pdo->exec("INSERT INTO users (user_id, username) VALUES (10, 'anchor@example.test')");
        $this->database->pdo->exec(
            "UPDATE sizestation_oidc_principals SET status = 'active', roundcube_user_id = 10"
            . " WHERE id = {$principalId}",
        );
        $this->runApplication([
            'sizestation-oidc', 'assignment:create', '--issuer=https://issuer.example',
            '--external-user-id=external-1', '--mailbox=anchor@example.test',
            '--credential-reference=mailboxes/anchor', '--anchor', '--password-stdin', '--json',
        ], "anchor-secret\n");
        [, $secondaryOutput] = $this->runApplication([
            'sizestation-oidc', 'assignment:create', '--issuer=https://issuer.example',
            '--external-user-id=external-1', '--mailbox=secondary@example.test',
            '--credential-reference=mailboxes/secondary', '--password-stdin', '--json',
        ], "secondary-secret\n");
        $secondaryId = (string) json_decode(
            $secondaryOutput,
            true,
            flags: JSON_THROW_ON_ERROR,
        )['assignment_id'];

        (new AdminRepository($this->database))->disableAssignment($secondaryId);

        self::assertSame(0, (int) $this->database->pdo->query(
            "SELECT flags & 1 FROM ident_switch WHERE managed_assignment_id = '{$secondaryId}'",
        )->fetchColumn());
        self::assertSame(0, (int) $this->database->pdo->query(
            "SELECT enabled FROM sizestation_mailbox_assignments WHERE id = '{$secondaryId}'",
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

    public function testTransientOpenBaoValidationFailurePreservesLastKnownValidStatus(): void
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
        $assignmentId = (string) json_decode($output, true, flags: JSON_THROW_ON_ERROR)['assignment_id'];

        [$exit] = $this->runApplication([
            'sizestation-oidc',
            'validate',
            '--assignment-id=' . $assignmentId,
            '--json',
        ], '', static function (): AccountCredentials {
            throw new ExternalCredentialException('openbao_unavailable', CredentialFailureKind::Unavailable);
        });

        self::assertSame(1, $exit);
        $assignment = $this->database->pdo->query(
            "SELECT credential_status, last_error_code FROM sizestation_mailbox_assignments"
            . " WHERE id = '{$assignmentId}'",
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('valid', $assignment['credential_status']);
        self::assertSame('openbao_unavailable', $assignment['last_error_code']);
        self::assertSame('openbao_unavailable', $this->database->pdo->query(
            'SELECT event_type FROM sizestation_oidc_audit_log ORDER BY id DESC LIMIT 1',
        )->fetchColumn());
    }

    public function testTransientMailboxValidationFailurePreservesLastKnownValidStatus(): void
    {
        [, $output] = $this->runApplication([
            'sizestation-oidc', 'assignment:create', '--issuer=https://issuer.example',
            '--external-user-id=external-1', '--mailbox=anchor@example.test',
            '--credential-reference=mailboxes/anchor', '--anchor', '--password-stdin', '--json',
        ], "anchor-secret\n");
        $assignmentId = (string) json_decode($output, true, flags: JSON_THROW_ON_ERROR)['assignment_id'];

        [$exit] = $this->runApplication([
            'sizestation-oidc',
            'assignment:validate',
            '--assignment-id=' . $assignmentId,
            '--json',
        ], '', null, new MailboxCredentialValidator('ssl://127.0.0.1:1', 'ssl://127.0.0.1:1', 1));

        self::assertSame(1, $exit);
        $assignment = $this->database->pdo->query(
            "SELECT credential_status, last_error_code FROM sizestation_mailbox_assignments"
            . " WHERE id = '{$assignmentId}'",
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('valid', $assignment['credential_status']);
        self::assertSame('mailbox_endpoint_unavailable', $assignment['last_error_code']);
    }

    public function testCanonicalAssignmentCommandsListShowEnableAndSelectPreLoginAnchor(): void
    {
        [, $anchorOutput] = $this->runApplication([
            'sizestation-oidc', 'assignment:create', '--issuer=https://issuer.example',
            '--external-user-id=external-1', '--mailbox=anchor@example.test',
            '--credential-reference=mailboxes/anchor', '--anchor', '--preferred', '--password-stdin', '--json',
        ], "anchor-secret\n");
        [, $secondaryOutput] = $this->runApplication([
            'sizestation-oidc', 'assignment:create', '--issuer=https://issuer.example',
            '--external-user-id=external-1', '--mailbox=secondary@example.test',
            '--credential-reference=mailboxes/secondary', '--password-stdin', '--json',
        ], "secondary-secret\n");
        $anchorId = (string) json_decode($anchorOutput, true, flags: JSON_THROW_ON_ERROR)['assignment_id'];
        $secondaryId = (string) json_decode($secondaryOutput, true, flags: JSON_THROW_ON_ERROR)['assignment_id'];

        [$listExit, $listOutput] = $this->runApplication([
            'sizestation-oidc', 'assignment:list', '--json',
        ], '');
        [$showExit, $showOutput] = $this->runApplication([
            'sizestation-oidc', 'assignment:show', '--assignment-id=' . $secondaryId, '--json',
        ], '');
        self::assertSame(0, $listExit);
        self::assertCount(2, json_decode($listOutput, true, flags: JSON_THROW_ON_ERROR)['assignments']);
        self::assertSame(0, $showExit);
        self::assertSame($secondaryId, json_decode(
            $showOutput,
            true,
            flags: JSON_THROW_ON_ERROR,
        )['assignment']['id']);

        [$anchorExit] = $this->runApplication([
            'sizestation-oidc', 'assignment:set-anchor', '--assignment-id=' . $secondaryId, '--json',
        ], '');
        self::assertSame(0, $anchorExit);
        self::assertSame(0, (int) $this->database->pdo->query(
            "SELECT is_anchor FROM sizestation_mailbox_assignments WHERE id = '{$anchorId}'",
        )->fetchColumn());
        self::assertSame(1, (int) $this->database->pdo->query(
            "SELECT is_anchor FROM sizestation_mailbox_assignments WHERE id = '{$secondaryId}'",
        )->fetchColumn());

        [$clearExit] = $this->runApplication([
            'sizestation-oidc', 'assignment:clear-preferred', '--assignment-id=' . $anchorId, '--json',
        ], '');
        self::assertSame(0, $clearExit);
        self::assertSame(0, (int) $this->database->pdo->query(
            'SELECT COUNT(*) FROM sizestation_mailbox_assignments WHERE is_preferred = 1',
        )->fetchColumn());

        [$disableExit] = $this->runApplication([
            'sizestation-oidc', 'assignment:disable', '--assignment-id=' . $anchorId, '--json',
        ], '');
        [$enableExit] = $this->runApplication([
            'sizestation-oidc', 'assignment:enable', '--assignment-id=' . $anchorId, '--json',
        ], '');
        self::assertSame(0, $disableExit);
        self::assertSame(0, $enableExit);
        self::assertSame(1, (int) $this->database->pdo->query(
            "SELECT enabled FROM sizestation_mailbox_assignments WHERE id = '{$anchorId}'",
        )->fetchColumn());
    }

    public function testCanonicalPrincipalCommandsListShowDisableAndEnable(): void
    {
        $principal = (new PrincipalRepository($this->database))->resolveOrCreate(
            'https://issuer.example',
            'subject-1',
            'external-1',
        );
        $principalId = (int) $principal['id'];

        [$listExit, $listOutput] = $this->runApplication([
            'sizestation-oidc', 'principal:list', '--json',
        ], '');
        [$showExit] = $this->runApplication([
            'sizestation-oidc', 'principal:show', '--principal-id=' . $principalId, '--json',
        ], '');
        [$disableExit] = $this->runApplication([
            'sizestation-oidc', 'principal:disable', '--principal-id=' . $principalId, '--json',
        ], '');
        [$enableExit, $enableOutput] = $this->runApplication([
            'sizestation-oidc', 'principal:enable', '--principal-id=' . $principalId, '--json',
        ], '');

        self::assertSame(0, $listExit);
        self::assertCount(1, json_decode($listOutput, true, flags: JSON_THROW_ON_ERROR)['principals']);
        self::assertSame(0, $showExit);
        self::assertSame(0, $disableExit);
        self::assertSame(0, $enableExit);
        self::assertSame('pending', json_decode(
            $enableOutput,
            true,
            flags: JSON_THROW_ON_ERROR,
        )['status']);
    }

    /** @param list<string> $arguments
     *  @return array{int, string, string}
     */
    private function runApplication(
        array $arguments,
        string $input,
        ?callable $resolver = null,
        ?MailboxCredentialValidator $validator = null,
    ): array {
        $stdin = fopen('php://memory', 'r+');
        fwrite($stdin, $input);
        rewind($stdin);
        $stdout = $stderr = '';
        $application = new Application(
            $this->database,
            $this->provisioner,
            $resolver ?? static fn (array $assignment): AccountCredentials => new AccountCredentials(
                (string) $assignment['mailbox_address'],
                'resolved-secret',
            ),
            $validator,
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
