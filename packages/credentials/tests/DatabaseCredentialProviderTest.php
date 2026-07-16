<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Credentials;

use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Credentials\CredentialContext;
use SizeStation\Roundcube\Credentials\CredentialPurpose;
use SizeStation\Roundcube\Credentials\Exception\InvalidAccountException;
use SizeStation\Roundcube\Credentials\Provider\DatabaseCredentialProvider;

final class DatabaseCredentialProviderTest extends TestCase
{
    public function testPreservesLegacyImapAndCustomProtocolCredentials(): void
    {
        $provider = new DatabaseCredentialProvider(fn (string $value): string => 'plain:' . $value);
        $credentials = $provider->getCredentials([
            'username' => 'mailbox@example.test',
            'password' => 'imap-ciphertext',
            'smtp_username' => 'smtp-user',
            'smtp_password' => 'smtp-ciphertext',
            'sieve_username' => 'sieve-user',
            'sieve_password' => 'sieve-ciphertext',
        ], new CredentialContext(CredentialPurpose::Imap));

        self::assertSame('mailbox@example.test', $credentials->imapUsername());
        self::assertSame('plain:imap-ciphertext', $credentials->imapPassword());
        self::assertSame('smtp-user', $credentials->smtpUsername());
        self::assertSame('plain:smtp-ciphertext', $credentials->smtpPassword());
        self::assertSame('sieve-user', $credentials->sieveUsername());
        self::assertSame('plain:sieve-ciphertext', $credentials->sievePassword());
    }

    public function testFallsBackToImapCredentials(): void
    {
        $provider = new DatabaseCredentialProvider(fn (string $value): string => $value);
        $credentials = $provider->getCredentials([
            'email' => 'fallback@example.test',
            'password' => 'secret',
        ], new CredentialContext(CredentialPurpose::Smtp));

        self::assertSame('fallback@example.test', $credentials->smtpUsername());
        self::assertSame('secret', $credentials->smtpPassword());
        self::assertSame('fallback@example.test', $credentials->sieveUsername());
        self::assertSame('secret', $credentials->sievePassword());
    }

    public function testRejectsDecryptionFailureWithSafeError(): void
    {
        $provider = new DatabaseCredentialProvider(fn (string $value): bool => false);

        $this->expectException(InvalidAccountException::class);
        $this->expectExceptionMessage('The account credential configuration is invalid.');

        $provider->getCredentials([
            'username' => 'mailbox@example.test',
            'password' => 'must-not-appear-in-exception',
        ], new CredentialContext(CredentialPurpose::Imap));
    }

    public function testDoesNotClaimExternallyManagedRows(): void
    {
        $provider = new DatabaseCredentialProvider(fn (string $value): string => $value);

        self::assertFalse($provider->supports([
            'credential_provider' => 'database',
            'managed_externally' => 1,
        ]));
    }
}
