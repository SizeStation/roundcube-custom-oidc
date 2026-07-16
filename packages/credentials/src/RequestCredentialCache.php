<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials;

final class RequestCredentialCache
{
    /** @var array<string, AccountCredentials> */
    private array $credentials = [];

    public function get(string $key): ?AccountCredentials
    {
        return $this->credentials[$key] ?? null;
    }

    public function put(string $key, AccountCredentials $credentials): AccountCredentials
    {
        return $this->credentials[$key] = $credentials;
    }

    public function clear(): void
    {
        foreach ($this->credentials as $credentials) {
            $credentials->erase();
        }

        $this->credentials = [];
    }

    public function __destruct()
    {
        $this->clear();
    }
}
