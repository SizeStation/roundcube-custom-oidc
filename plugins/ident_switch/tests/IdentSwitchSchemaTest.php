<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\IdentSwitch;

use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class IdentSwitchSchemaTest extends TestCase
{
    public function testFreshSqliteSchemaContainsManagedCredentialFields(): void
    {
        $database = $this->databaseWithRoundcubeTables();
        $database->exec(file_get_contents(__DIR__ . '/../SQL/sqlite.initial.sql'));

        $columns = $database->query('PRAGMA table_info(ident_switch)')->fetchAll(PDO::FETCH_COLUMN, 1);

        self::assertContains('credential_provider', $columns);
        self::assertContains('credential_reference', $columns);
        self::assertContains('managed_externally', $columns);
        self::assertContains('managed_assignment_id', $columns);
        self::assertSame(
            '2026071600',
            $database->query("SELECT value FROM system WHERE name = 'ident_switch-version'")->fetchColumn(),
        );
    }

    public function testManagedAssignmentReferenceIsUnique(): void
    {
        $database = $this->databaseWithRoundcubeTables();
        $database->exec(file_get_contents(__DIR__ . '/../SQL/sqlite.initial.sql'));
        $database->exec("INSERT INTO users (user_id) VALUES (1)");
        $database->exec("INSERT INTO identities (identity_id, user_id) VALUES (1, 1), (2, 1)");
        $database->exec(
            "INSERT INTO ident_switch (user_id, iid, managed_assignment_id) VALUES (1, 1, 'assignment-id')",
        );

        $this->expectException(\PDOException::class);
        $database->exec(
            "INSERT INTO ident_switch (user_id, iid, managed_assignment_id) VALUES (1, 2, 'assignment-id')",
        );
    }

    public function testUpgradeAddsManagedCredentialFields(): void
    {
        $database = $this->databaseWithRoundcubeTables();
        $database->exec('CREATE TABLE ident_switch (id integer primary key)');
        $database->exec("INSERT INTO system (name, value) VALUES ('ident_switch-version', '2026021000')");
        $database->exec(file_get_contents(__DIR__ . '/../SQL/sqlite/2026071600.sql'));

        $columns = $database->query('PRAGMA table_info(ident_switch)')->fetchAll(PDO::FETCH_COLUMN, 1);
        self::assertContains('managed_assignment_id', $columns);
        self::assertSame(
            '2026071600',
            $database->query("SELECT value FROM system WHERE name = 'ident_switch-version'")->fetchColumn(),
        );
    }

    private function databaseWithRoundcubeTables(): PDO
    {
        $database = new PDO('sqlite::memory:');
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->exec('PRAGMA foreign_keys = ON');
        $database->exec('CREATE TABLE system (name varchar(64) primary key, value text)');
        $database->exec('CREATE TABLE users (user_id integer primary key)');
        $database->exec(
            'CREATE TABLE identities ('
            . 'identity_id integer primary key, user_id integer not null, email varchar(255), del integer default 0'
            . ')',
        );

        return $database;
    }
}
