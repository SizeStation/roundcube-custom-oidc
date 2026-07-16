<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials\Provider;

use SizeStation\Roundcube\Credentials\AccountCredentials;
use SizeStation\Roundcube\Credentials\CredentialContext;
use SizeStation\Roundcube\Credentials\CredentialProviderInterface;
use SizeStation\Roundcube\Credentials\Exception\InvalidAccountException;
use SizeStation\Roundcube\Credentials\OpenBao\CredentialReference;
use SizeStation\Roundcube\Credentials\OpenBao\OpenBaoKvV2Client;

final class OpenBaoCredentialProvider implements CredentialProviderInterface
{
    public function __construct(private readonly OpenBaoKvV2Client $client)
    {
    }

    public function name(): string
    {
        return 'openbao';
    }

    public function supports(array $account): bool
    {
        return ($account['credential_provider'] ?? null) === $this->name()
            && !empty($account['managed_externally']);
    }

    public function getCredentials(array $account, CredentialContext $context): AccountCredentials
    {
        $reference = new CredentialReference((string) ($account['credential_reference'] ?? ''));
        $secret = $this->client->read($reference);
        $username = $this->requiredString($secret, 'username');
        $password = $this->requiredString($secret, 'password');

        return new AccountCredentials(
            $username,
            $password,
            $this->optionalString($secret, 'smtp_username'),
            $this->optionalString($secret, 'smtp_password'),
            $this->optionalString($secret, 'sieve_username'),
            $this->optionalString($secret, 'sieve_password'),
        );
    }

    /** @param array<string, mixed> $secret */
    private function requiredString(array $secret, string $key): string
    {
        $value = $secret[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidAccountException('credential_secret_fields_invalid');
        }

        return $value;
    }

    /** @param array<string, mixed> $secret */
    private function optionalString(array $secret, string $key): ?string
    {
        $value = $secret[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidAccountException('credential_secret_fields_invalid');
        }

        return $value;
    }
}
