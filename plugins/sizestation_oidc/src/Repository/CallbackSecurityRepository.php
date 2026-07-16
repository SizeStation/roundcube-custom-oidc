<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Repository;

use RuntimeException;

final class CallbackSecurityRepository
{
    public function __construct(private readonly \rcube_db $database)
    {
    }

    public function assertAttemptAllowed(string $source, int $maximumAttempts = 20, int $windowSeconds = 300): void
    {
        $now = time();
        $key = 'callback:' . hash('sha256', $source);
        $this->cleanup($now);

        if (!$this->database->startTransaction()) {
            throw new RepositoryException('Unable to start callback rate-limit transaction');
        }
        try {
            $query = $this->database->query(
                'SELECT attempts, expires_at FROM ' . $this->database->table_name('sizestation_oidc_rate_limits')
                . ' WHERE limiter_key = ?',
                $key,
            );
            $row = $this->database->fetch_assoc($query);
            if (is_array($row) && strtotime((string) $row['expires_at']) >= $now) {
                $attempts = (int) $row['attempts'] + 1;
                if ($attempts > $maximumAttempts) {
                    throw new RuntimeException('OIDC callback rate limit exceeded');
                }
                $updated = $this->database->query(
                    'UPDATE ' . $this->database->table_name('sizestation_oidc_rate_limits')
                    . ' SET attempts = ? WHERE limiter_key = ?',
                    $attempts,
                    $key,
                );
            } else {
                if (is_array($row)) {
                    $this->database->query(
                        'DELETE FROM ' . $this->database->table_name('sizestation_oidc_rate_limits')
                        . ' WHERE limiter_key = ?',
                        $key,
                    );
                }
                $updated = $this->database->query(
                    'INSERT INTO ' . $this->database->table_name('sizestation_oidc_rate_limits')
                    . ' (limiter_key, window_started_at, attempts, expires_at) VALUES (?, ?, ?, ?)',
                    $key,
                    $this->timestamp($now),
                    1,
                    $this->timestamp($now + $windowSeconds),
                );
            }
            if (!$updated || !$this->database->endTransaction()) {
                throw new RepositoryException('Unable to persist callback rate limit');
            }
        } catch (\Throwable $exception) {
            $this->database->rollbackTransaction();
            throw $exception;
        }
    }

    public function claimAuthorizationCode(string $code, int $ttlSeconds = 600): void
    {
        $now = time();
        $hash = hash('sha256', $code);
        $this->cleanup($now);
        $query = $this->database->query(
            'INSERT INTO ' . $this->database->table_name('sizestation_oidc_replay_codes')
            . ' (code_hash, expires_at, created_at) VALUES (?, ?, ?)',
            $hash,
            $this->timestamp($now + $ttlSeconds),
            $this->timestamp($now),
        );
        if (!$query) {
            throw new RuntimeException('OIDC authorization code has already been used');
        }
    }

    private function cleanup(int $now): void
    {
        $timestamp = $this->timestamp($now);
        $this->database->query(
            'DELETE FROM ' . $this->database->table_name('sizestation_oidc_replay_codes') . ' WHERE expires_at < ?',
            $timestamp,
        );
        $this->database->query(
            'DELETE FROM ' . $this->database->table_name('sizestation_oidc_rate_limits') . ' WHERE expires_at < ?',
            $timestamp,
        );
    }

    private function timestamp(int $time): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $time);
    }
}
