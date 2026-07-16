<?php

declare(strict_types=1);

// phpcs:disable PSR1.Methods.CamelCapsMethodName, PSR1.Classes.ClassDeclaration.MultipleClasses

namespace {
    if (!class_exists('rcube_db', false)) {
        final class rcube_db
        {
            private \PDOStatement|false $lastResult = false;

            public function __construct(public readonly \PDO $pdo)
            {
            }

            public function table_name(string $table): string
            {
                return $table;
            }

            public function query(string $sql, mixed ...$parameters): \PDOStatement|false
            {
                $statement = $this->pdo->prepare($sql);
                if (!$statement->execute($parameters)) {
                    return false;
                }

                return $this->lastResult = $statement;
            }

            public function fetch_assoc(\PDOStatement|false $statement): array|false
            {
                return $statement ? $statement->fetch(\PDO::FETCH_ASSOC) : false;
            }

            public function affected_rows(\PDOStatement|false $statement = false): int
            {
                $result = $statement ?: $this->lastResult;

                return $result ? $result->rowCount() : 0;
            }

            public function startTransaction(): bool
            {
                return $this->pdo->beginTransaction();
            }

            public function endTransaction(): bool
            {
                return $this->pdo->commit();
            }

            public function rollbackTransaction(): bool
            {
                return $this->pdo->inTransaction() ? $this->pdo->rollBack() : true;
            }
        }
    }
}

namespace SizeStation\Roundcube\Tests\Oidc {
    use PDO;
    use PHPUnit\Framework\Attributes\RequiresPhpExtension;
    use PHPUnit\Framework\TestCase;
    use SizeStation\Roundcube\Oidc\Audit\AuditEvent;
    use SizeStation\Roundcube\Oidc\Repository\AssignmentRepository;
    use SizeStation\Roundcube\Oidc\Repository\AuditLogRepository;
    use SizeStation\Roundcube\Oidc\Repository\CallbackSecurityRepository;
    use SizeStation\Roundcube\Oidc\Repository\PrincipalRepository;
    use SizeStation\Roundcube\Oidc\Repository\RepositoryException;

    #[RequiresPhpExtension('pdo_sqlite')]
    final class RepositoryTest extends TestCase
    {
        private \rcube_db $database;

        protected function setUp(): void
        {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('CREATE TABLE system (name varchar(64) primary key, value text)');
            $pdo->exec(file_get_contents(__DIR__ . '/../SQL/sqlite.initial.sql'));
            $this->database = new \rcube_db($pdo);
        }

        public function testPrincipalBindingIsStableAndRejectsExternalMismatch(): void
        {
            $repository = new PrincipalRepository($this->database);
            $principal = $repository->resolveOrCreate(
                'https://issuer.example',
                'subject-1',
                'external-1',
                ['email' => 'user@example.test'],
            );

            self::assertSame('external-1', $principal['external_user_id']);
            self::assertSame(
                $principal['id'],
                $repository->resolveOrCreate(
                    'https://issuer.example',
                    'subject-1',
                    'external-1',
                )['id'],
            );

            $this->expectException(RepositoryException::class);
            $repository->resolveOrCreate('https://issuer.example', 'subject-1', 'external-other');
        }

        public function testPendingAssignmentsBindAtomicallyAndResolveAnchor(): void
        {
            $principals = new PrincipalRepository($this->database);
            $principal = $principals->resolveOrCreate(
                'https://issuer.example',
                'subject-1',
                'external-1',
            );
            $this->insertAnchor();
            $assignments = new AssignmentRepository($this->database);

            $bound = $assignments->bindPending(
                (int) $principal['id'],
                'https://issuer.example',
                'external-1',
            );

            self::assertCount(1, $bound);
            self::assertSame($bound[0]['id'], $assignments->anchor($bound)['id']);
            self::assertSame((int) $principal['id'], (int) $bound[0]['principal_id']);
        }

        public function testAuditRepositoryRedactsSecretsBeforePersistence(): void
        {
            $repository = new AuditLogRepository($this->database);
            $repository->record(
                AuditEvent::OidcLoginFailure,
                'system',
                'test',
                metadata: ['password' => 'must-not-persist', 'error_code' => 'safe'],
            );

            $json = $this->database->pdo
                ->query('SELECT metadata_json FROM sizestation_oidc_audit_log')
                ->fetchColumn();
            self::assertStringNotContainsString('must-not-persist', $json);
            self::assertStringContainsString('[REDACTED]', $json);
            self::assertStringContainsString('safe', $json);
        }

        public function testAuthorizationCodeClaimIsPersistentAndSingleUse(): void
        {
            $repository = new CallbackSecurityRepository($this->database);
            $repository->claimAuthorizationCode('one-time-code');
            self::assertSame(1, (int) $this->database->pdo->query(
                'SELECT COUNT(*) FROM sizestation_oidc_replay_codes',
            )->fetchColumn());

            $this->expectException(\Throwable::class);
            $repository->claimAuthorizationCode('one-time-code');
        }

        public function testCallbackRateLimitIsScopedAndEnforced(): void
        {
            $repository = new CallbackSecurityRepository($this->database);
            $repository->assertAttemptAllowed('192.0.2.10', 2, 300);
            $repository->assertAttemptAllowed('192.0.2.10', 2, 300);
            $repository->assertAttemptAllowed('192.0.2.11', 2, 300);

            $this->expectException(\RuntimeException::class);
            $repository->assertAttemptAllowed('192.0.2.10', 2, 300);
        }

        private function insertAnchor(): void
        {
            $statement = $this->database->pdo->prepare(
                'INSERT INTO sizestation_mailbox_assignments ('
                . 'id, issuer, external_user_id, mailbox_address, credential_provider, credential_reference,'
                . ' is_anchor, is_preferred, enabled, anchor_guard, created_by, created_at, updated_at'
                . ') VALUES (?, ?, ?, ?, ?, ?, 1, 0, 1, ?, ?, ?, ?)',
            );
            $statement->execute([
                '00000000-0000-4000-8000-000000000001',
                'https://issuer.example',
                'external-1',
                'user@example.test',
                'openbao',
                'assignment/one',
                'anchor',
                'test',
                'now',
                'now',
            ]);
        }
    }
}
