<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Oidc;

use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SizeStation\Roundcube\Oidc\Repository\PrincipalRepository;
use SizeStation\Roundcube\Oidc\Service\RuntimeIdentityGuard;

#[RequiresPhpExtension('pdo_sqlite')]
final class RuntimeIdentityGuardTest extends TestCase
{
    private \rcube_db $database;
    private PrincipalRepository $principals;
    private RuntimeIdentityGuard $guard;
    /** @var array<string, mixed> */
    private array $principal;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE system (name varchar(64) primary key, value text)');
        $pdo->exec(file_get_contents(dirname(__DIR__, 3) . '/SQL/sqlite.initial.sql'));
        $pdo->exec(
            'CREATE TABLE users (user_id integer primary key, username varchar(254), mail_host varchar(255))',
        );
        $this->database = new \rcube_db($pdo);
        $this->principals = new PrincipalRepository($this->database);
        $this->principal = $this->principals->resolveOrCreate(
            'https://issuer.example',
            'subject-1',
            'external-1',
        );
        $this->principals->activate((int) $this->principal['id'], 42);
        $this->principal = $this->principals->findById((int) $this->principal['id']) ?? [];
        $pdo->exec(
            "INSERT INTO users (user_id, username, mail_host) VALUES (42, 'anchor@example.test', 'imap.example.test')",
        );
        $this->guard = new RuntimeIdentityGuard($this->principals, $this->database);
    }

    public function testEstablishedSessionAndAnchorMappingAreAccepted(): void
    {
        $principal = $this->guard->assertEstablishedSession($this->identity(), 42);
        $this->guard->assertAnchorMapping(
            $principal,
            'anchor@example.test',
            'ANCHOR@example.test',
            'ssl://imap.example.test:993',
        );

        self::assertSame((int) $this->principal['id'], (int) $principal['id']);
    }

    public function testDisabledPrincipalInvalidatesEstablishedSession(): void
    {
        $this->database->pdo->exec(
            "UPDATE sizestation_oidc_principals SET status = 'disabled' WHERE id = " . (int) $this->principal['id'],
        );

        $this->expectException(RuntimeException::class);
        $this->guard->assertEstablishedSession($this->identity(), 42);
    }

    public function testSessionCannotMoveToAnotherRoundcubeUser(): void
    {
        $this->expectException(RuntimeException::class);
        $this->guard->assertEstablishedSession($this->identity(), 43);
    }

    public function testMissingMappedRoundcubeUserIsRejectedBeforeAuthentication(): void
    {
        $this->database->pdo->exec('DELETE FROM users WHERE user_id = 42');

        $this->expectException(RuntimeException::class);
        $this->guard->assertAnchorMapping(
            $this->principal,
            'anchor@example.test',
            'anchor@example.test',
            'ssl://imap.example.test:993',
        );
    }

    public function testChangedAnchorMappingIsRejectedBeforeAuthentication(): void
    {
        $this->database->pdo->exec("UPDATE users SET username = 'other@example.test' WHERE user_id = 42");

        $this->expectException(RuntimeException::class);
        $this->guard->assertAnchorMapping(
            $this->principal,
            'anchor@example.test',
            'anchor@example.test',
            'ssl://imap.example.test:993',
        );
    }

    /** @return array<string, mixed> */
    private function identity(): array
    {
        return [
            'principal_id' => (int) $this->principal['id'],
            'issuer' => 'https://issuer.example',
            'subject' => 'subject-1',
            'external_user_id' => 'external-1',
        ];
    }
}
