<?php

declare(strict_types=1);

// phpcs:disable

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!class_exists('rcube_db', false)) {
    final class rcube_db
    {
        private \PDOStatement|false $lastResult = false;

        public function __construct(public readonly \PDO $pdo)
        {
        }

        public function table_name(string $table, bool $quoted = false): string
        {
            return $table;
        }

        public function query(string $sql, mixed ...$parameters): \PDOStatement|false
        {
            if (count($parameters) === 1 && is_array($parameters[0])) {
                $parameters = $parameters[0];
            }
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

        public function now(): string
        {
            return 'CURRENT_TIMESTAMP';
        }

        public function insert_id(string $table): string
        {
            return $this->pdo->lastInsertId();
        }
    }
}
