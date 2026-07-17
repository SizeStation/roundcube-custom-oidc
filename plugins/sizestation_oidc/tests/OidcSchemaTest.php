<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Oidc;

use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class OidcSchemaTest extends TestCase
{
    public function testFreshSchemaCreatesAllPluginTables(): void
    {
        $database = $this->database();

        $tables = [
            'sizestation_oidc_principals',
            'sizestation_mailbox_assignments',
            'sizestation_oidc_audit_log',
            'sizestation_oidc_replay_codes',
            'sizestation_oidc_rate_limits',
            'ident_switch',
        ];
        foreach ($tables as $table) {
            $count = $database->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = "
                . $database->quote($table),
            )->fetchColumn();
            self::assertSame(1, (int) $count);
        }
    }

    public function testDatabasePreventsTwoEnabledAnchorsForExternalUser(): void
    {
        $database = $this->database();
        $this->insertAssignment($database, '00000000-0000-4000-8000-000000000001', 'one@example.test');

        $this->expectException(\PDOException::class);
        $this->insertAssignment($database, '00000000-0000-4000-8000-000000000002', 'two@example.test');
    }

    public function testDatabaseRejectsAnchorWithoutGuard(): void
    {
        $database = $this->database();

        $this->expectException(\PDOException::class);
        $database->exec(
            "INSERT INTO sizestation_mailbox_assignments ("
            . 'id, issuer, external_user_id, mailbox_address, credential_provider, credential_reference,'
            . ' is_anchor, is_preferred, enabled, anchor_guard, preferred_guard, created_by, created_at, updated_at'
            . ") VALUES ('00000000-0000-4000-8000-000000000003', 'https://issuer.example', 'external-1',"
            . " 'three@example.test', 'openbao', 'assignment/three', 1, 0, 1, NULL, NULL, 'test', 'now', 'now')",
        );
    }

    public function testDatabaseRejectsCredentialReferenceReuse(): void
    {
        $database = $this->database();
        $this->insertAssignment($database, '00000000-0000-4000-8000-000000000001', 'one@example.test');

        $this->expectException(\PDOException::class);
        $statement = $database->prepare(
            'INSERT INTO sizestation_mailbox_assignments ('
            . 'id, issuer, external_user_id, mailbox_address, credential_provider, credential_reference,'
            . ' is_anchor, is_preferred, enabled, anchor_guard, created_by, created_at, updated_at'
            . ') VALUES (?, ?, ?, ?, ?, ?, 1, 0, 1, ?, ?, ?, ?)',
        );
        $statement->execute([
            '00000000-0000-4000-8000-000000000002',
            'https://issuer.example',
            'external-2',
            'two@example.test',
            'openbao',
            'assignment/00000000-0000-4000-8000-000000000001',
            'anchor',
            'test',
            'now',
            'now',
        ]);
    }

    public function testFreshSchemaRecordsLatestVersion(): void
    {
        self::assertSame('2026071602', $this->database()->query(
            "SELECT value FROM system WHERE name = 'sizestation_oidc-version'",
        )->fetchColumn());
        self::assertSame('2026071700', $this->database()->query(
            "SELECT value FROM system WHERE name = 'roundcube_oidc_suite-version'",
        )->fetchColumn());
    }

    private function database(): PDO
    {
        $database = new PDO('sqlite::memory:');
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->exec('PRAGMA foreign_keys = ON');
        $database->exec('CREATE TABLE system (name varchar(64) primary key, value text)');
        $database->exec(file_get_contents(dirname(__DIR__, 3) . '/SQL/sqlite.initial.sql'));

        return $database;
    }

    private function insertAssignment(PDO $database, string $id, string $mailbox): void
    {
        $statement = $database->prepare(
            'INSERT INTO sizestation_mailbox_assignments ('
            . 'id, issuer, external_user_id, mailbox_address, credential_provider, credential_reference,'
            . ' is_anchor, is_preferred, enabled, anchor_guard, preferred_guard, created_by, created_at, updated_at'
            . ') VALUES (?, ?, ?, ?, ?, ?, 1, 0, 1, ?, NULL, ?, ?, ?)',
        );
        $statement->execute([
            $id,
            'https://issuer.example',
            'external-1',
            $mailbox,
            'openbao',
            'assignment/' . $id,
            'anchor',
            'test',
            'now',
            'now',
        ]);
    }
}
