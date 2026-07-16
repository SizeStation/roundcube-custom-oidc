<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Security;

use RuntimeException;

final class OidcStateManager
{
    private const SESSION_KEY = 'sizestation_oidc.authorization';

    /** @param array<string, mixed> $session */
    public function create(array &$session, int $now = 0): OidcState
    {
        $state = $this->random(32);
        $nonce = $this->random(32);
        $verifier = $this->random(64);
        $challenge = $this->encode(hash('sha256', $verifier, true));
        $session[self::SESSION_KEY] = [
            'state_hash' => hash('sha256', $state),
            'nonce' => $nonce,
            'code_verifier' => $verifier,
            'created_at' => $now ?: time(),
        ];

        return new OidcState($state, $nonce, $verifier, $challenge);
    }

    /** @param array<string, mixed> $session
     *  @return array{nonce: string, code_verifier: string}
     */
    public function consume(array &$session, string $state, int $ttlSeconds = 300, int $now = 0): array
    {
        $pending = $session[self::SESSION_KEY] ?? null;
        unset($session[self::SESSION_KEY]);
        $now = $now ?: time();

        if (
            !is_array($pending)
            || !is_string($pending['state_hash'] ?? null)
            || !hash_equals($pending['state_hash'], hash('sha256', $state))
            || !is_int($pending['created_at'] ?? null)
            || $pending['created_at'] > $now + 30
            || $pending['created_at'] < $now - $ttlSeconds
            || !is_string($pending['nonce'] ?? null)
            || !is_string($pending['code_verifier'] ?? null)
        ) {
            throw new RuntimeException('OIDC authorization state is invalid or expired');
        }

        return ['nonce' => $pending['nonce'], 'code_verifier' => $pending['code_verifier']];
    }

    /** @param array<string, mixed> $session */
    public function consumeError(array &$session, string $state, int $ttlSeconds = 300, int $now = 0): void
    {
        $this->consume($session, $state, $ttlSeconds, $now);
    }

    private function random(int $bytes): string
    {
        return $this->encode(random_bytes($bytes));
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
