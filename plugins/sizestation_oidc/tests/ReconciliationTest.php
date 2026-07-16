<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Oidc;

use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Oidc\Reconciliation\AssignmentReconciler;
use SizeStation\Roundcube\Oidc\Reconciliation\RecoverableMaterializationException;
use SizeStation\Roundcube\Oidc\Repository\AssignmentRepository;
use SizeStation\Roundcube\Oidc\Repository\PrincipalRepository;
use SizeStation\Roundcube\Oidc\Service\LoginFinalizer;

#[RequiresPhpExtension('pdo_sqlite')]
final class ReconciliationTest extends TestCase
{
    private \rcube_db $database;
    private int $principalId;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE system (name varchar(64) primary key, value text)');
        $pdo->exec('CREATE TABLE users (user_id integer PRIMARY KEY, username text)');
        $pdo->exec('INSERT INTO users (user_id, username) VALUES (10, \'anchor@example.test\')');
        $pdo->exec('CREATE TABLE identities ('
            . 'identity_id integer PRIMARY KEY, user_id integer NOT NULL, changed text NOT NULL,'
            . 'del integer NOT NULL DEFAULT 0, standard integer NOT NULL DEFAULT 0,'
            . 'name varchar(128) NOT NULL DEFAULT \'\', email varchar(128) NOT NULL DEFAULT \'\','
            . 'signature text NOT NULL DEFAULT \'\', html_signature integer NOT NULL DEFAULT 0)');
        $pdo->exec(file_get_contents(__DIR__ . '/../SQL/sqlite.initial.sql'));
        $pdo->exec(file_get_contents(__DIR__ . '/../../ident_switch/SQL/sqlite.initial.sql'));
        $this->database = new \rcube_db($pdo);

        $principal = (new PrincipalRepository($this->database))->resolveOrCreate(
            'https://issuer.example',
            'subject-1',
            'external-1',
        );
        $this->principalId = (int) $principal['id'];
        $this->insertAssignment('00000000-0000-4000-8000-000000000001', 'anchor@example.test', true, false);
        $this->insertAssignment('00000000-0000-4000-8000-000000000002', 'secondary@example.test', false, true);
    }

    public function testMaterializationIsIdempotentAndUsesExternalCredentials(): void
    {
        $assignments = new AssignmentRepository($this->database);
        $bound = $assignments->bindPending($this->principalId, 'https://issuer.example', 'external-1');
        $reconciler = new AssignmentReconciler($this->database);

        $first = $reconciler->reconcile($this->principalId, 10, $bound);
        self::assertSame(1, $first->created);
        self::assertNotNull($first->preferredSwitchRecordId);
        self::assertSame('00000000-0000-4000-8000-000000000002', $first->materialized[0]['assignment_id']);

        $row = $this->database->pdo->query(
            'SELECT password, credential_provider, credential_reference, managed_externally'
            . ' FROM ident_switch',
        )->fetch(PDO::FETCH_ASSOC);
        self::assertNull($row['password']);
        self::assertSame('openbao', $row['credential_provider']);
        self::assertSame('mailboxes/secondary', $row['credential_reference']);
        self::assertSame(1, (int) $row['managed_externally']);

        $second = $reconciler->reconcile($this->principalId, 10, $assignments->forPrincipal(
            $this->principalId,
            'https://issuer.example',
            'external-1',
        ));
        self::assertSame(0, $second->created);
        self::assertSame(1, $second->updated);
        self::assertSame(1, (int) $this->database->pdo->query('SELECT COUNT(*) FROM ident_switch')->fetchColumn());
        self::assertSame(1, (int) $this->database->pdo->query('SELECT COUNT(*) FROM identities')->fetchColumn());
    }

    public function testReconciliationRepairsManagedAuthenticationAndNotificationDrift(): void
    {
        $assignments = new AssignmentRepository($this->database);
        $bound = $assignments->bindPending($this->principalId, 'https://issuer.example', 'external-1');
        $reconciler = new AssignmentReconciler($this->database);
        $reconciler->reconcile($this->principalId, 10, $bound);
        $this->database->pdo->exec(
            'UPDATE ident_switch SET smtp_auth = 0, sieve_auth = 0, notify_check = 0',
        );

        $reconciler->reconcile($this->principalId, 10, $assignments->forPrincipal(
            $this->principalId,
            'https://issuer.example',
            'external-1',
        ));

        $row = $this->database->pdo->query(
            'SELECT smtp_auth, sieve_auth, notify_check FROM ident_switch',
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $row['smtp_auth']);
        self::assertSame(1, (int) $row['sieve_auth']);
        self::assertSame(1, (int) $row['notify_check']);
    }

    public function testDisabledAssignmentDisablesManagedRecordWithoutDeletingIdentity(): void
    {
        $repository = new AssignmentRepository($this->database);
        $bound = $repository->bindPending($this->principalId, 'https://issuer.example', 'external-1');
        $reconciler = new AssignmentReconciler($this->database);
        $reconciler->reconcile($this->principalId, 10, $bound);
        $this->database->pdo->exec(
            "UPDATE sizestation_mailbox_assignments SET enabled = 0, is_preferred = 0, preferred_guard = NULL"
            . " WHERE id = '00000000-0000-4000-8000-000000000002'",
        );

        $result = $reconciler->reconcile($this->principalId, 10, $repository->forPrincipal(
            $this->principalId,
            'https://issuer.example',
            'external-1',
        ));
        self::assertSame(1, $result->disabled);
        self::assertSame(0, (int) $this->database->pdo->query('SELECT flags FROM ident_switch')->fetchColumn());
        self::assertSame(1, (int) $this->database->pdo->query('SELECT COUNT(*) FROM identities')->fetchColumn());
    }

    public function testSecondaryMaterializationFailurePreservesInitializedAnchorAndRollsBackSecondaries(): void
    {
        $repository = new AssignmentRepository($this->database);
        $this->insertAssignment(
            '00000000-0000-4000-8000-000000000003',
            'tertiary@example.test',
            false,
            false,
            'mailboxes/tertiary',
        );
        $bound = $repository->bindPending($this->principalId, 'https://issuer.example', 'external-1');
        $bound[2]['principal_id'] = 999;

        try {
            (new LoginFinalizer($this->database))->finalize(
                $this->principalId,
                10,
                '00000000-0000-4000-8000-000000000001',
                $bound,
            );
            self::fail('A changed assignment owner must abort login finalization');
        } catch (RecoverableMaterializationException $exception) {
            self::assertSame(
                'Assignment ownership changed during reconciliation',
                $exception->getPrevious()?->getMessage(),
            );
        }

        $principal = $this->database->pdo->query(
            'SELECT status, roundcube_user_id, first_login_at FROM sizestation_oidc_principals',
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('active', $principal['status']);
        self::assertSame(10, (int) $principal['roundcube_user_id']);
        self::assertNotNull($principal['first_login_at']);

        $anchor = $this->database->pdo->query(
            "SELECT materialization_status, credential_status FROM sizestation_mailbox_assignments"
            . " WHERE id = '00000000-0000-4000-8000-000000000001'",
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('anchor', $anchor['materialization_status']);
        self::assertSame('valid', $anchor['credential_status']);
        self::assertSame(0, (int) $this->database->pdo->query('SELECT COUNT(*) FROM ident_switch')->fetchColumn());
        self::assertSame(0, (int) $this->database->pdo->query('SELECT COUNT(*) FROM identities')->fetchColumn());
    }

    private function insertAssignment(
        string $id,
        string $mailbox,
        bool $anchor,
        bool $preferred,
        ?string $credentialReference = null,
    ): void {
        $statement = $this->database->pdo->prepare(
            'INSERT INTO sizestation_mailbox_assignments ('
            . 'id, issuer, external_user_id, mailbox_address, credential_provider, credential_reference,'
            . ' is_anchor, is_preferred, enabled, anchor_guard, preferred_guard, created_by, created_at, updated_at'
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $id,
            'https://issuer.example',
            'external-1',
            $mailbox,
            'openbao',
            $credentialReference ?? ($anchor ? 'mailboxes/anchor' : 'mailboxes/secondary'),
            $anchor ? 1 : 0,
            $preferred ? 1 : 0,
            $anchor ? 'anchor' : null,
            $preferred ? 'preferred' : null,
            'test',
            'now',
            'now',
        ]);
    }
}
