<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials;

use SizeStation\Roundcube\Credentials\Exception\ProviderNotFoundException;

final class CredentialProviderRegistry
{
    /** @var array<string, CredentialProviderInterface> */
    private array $providers = [];

    public function __construct(
        iterable $providers,
        private readonly RequestCredentialCache $cache = new RequestCredentialCache(),
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->name()] = $provider;
        }
    }

    /** @param array<string, mixed> $account */
    public function getCredentials(array $account, CredentialContext $context): AccountCredentials
    {
        $providerName = trim((string) ($account['credential_provider'] ?? '')) ?: 'database';
        $provider = $this->providers[$providerName] ?? null;

        if ($provider === null || !$provider->supports($account)) {
            throw new ProviderNotFoundException();
        }

        $key = $this->cacheKey($providerName, $account);
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            return $cached;
        }

        return $this->cache->put($key, $provider->getCredentials($account, $context));
    }

    public function clear(): void
    {
        $this->cache->clear();
    }

    /** @param array<string, mixed> $account */
    private function cacheKey(string $providerName, array $account): string
    {
        $reference = trim((string) ($account['credential_reference'] ?? ''));
        if ($reference === '') {
            $reference = 'account:' . (string) ($account['id'] ?? $account['iid'] ?? 'unknown');
        }

        return hash('sha256', $providerName . "\0" . $reference);
    }
}
