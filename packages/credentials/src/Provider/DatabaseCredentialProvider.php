<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials\Provider;

use Closure;
use SizeStation\Roundcube\Credentials\AccountCredentials;
use SizeStation\Roundcube\Credentials\CredentialContext;
use SizeStation\Roundcube\Credentials\CredentialProviderInterface;
use SizeStation\Roundcube\Credentials\Exception\InvalidAccountException;

final class DatabaseCredentialProvider implements CredentialProviderInterface
{
    /** @var Closure(string): string|false */
    private readonly Closure $decrypt;

    /** @param callable(string): string|false $decrypt */
    public function __construct(callable $decrypt)
    {
        $this->decrypt = Closure::fromCallable($decrypt);
    }

    public function name(): string
    {
        return 'database';
    }

    public function supports(array $account): bool
    {
        $provider = trim((string) ($account['credential_provider'] ?? ''));

        return ($provider === '' || $provider === $this->name())
            && empty($account['managed_externally']);
    }

    public function getCredentials(array $account, CredentialContext $context): AccountCredentials
    {
        $username = trim((string) ($account['username'] ?? $account['email'] ?? ''));
        $password = $this->decryptRequired($account['password'] ?? null);

        if ($username === '') {
            throw new InvalidAccountException('credential_username_missing');
        }

        return new AccountCredentials(
            $username,
            $password,
            $this->optionalString($account['smtp_username'] ?? null),
            $this->decryptOptional($account['smtp_password'] ?? null),
            $this->optionalString($account['sieve_username'] ?? null),
            $this->decryptOptional($account['sieve_password'] ?? null),
        );
    }

    private function decryptRequired(mixed $encrypted): string
    {
        if (!is_string($encrypted) || $encrypted === '') {
            throw new InvalidAccountException('credential_password_missing');
        }

        $decrypted = ($this->decrypt)($encrypted);
        if (!is_string($decrypted)) {
            throw new InvalidAccountException('credential_decryption_failed');
        }

        return $decrypted;
    }

    private function decryptOptional(mixed $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        if (!is_string($encrypted)) {
            throw new InvalidAccountException('credential_password_invalid');
        }

        $decrypted = ($this->decrypt)($encrypted);
        if (!is_string($decrypted)) {
            throw new InvalidAccountException('credential_decryption_failed');
        }

        return $decrypted;
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
