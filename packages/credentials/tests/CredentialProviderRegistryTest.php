<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Credentials;

use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Credentials\CredentialContext;
use SizeStation\Roundcube\Credentials\CredentialProviderRegistry;
use SizeStation\Roundcube\Credentials\CredentialPurpose;
use SizeStation\Roundcube\Credentials\Exception\InvalidAccountException;
use SizeStation\Roundcube\Credentials\Exception\ProviderNotFoundException;
use SizeStation\Roundcube\Credentials\Provider\DatabaseCredentialProvider;

final class CredentialProviderRegistryTest extends TestCase
{
    public function testCachesCredentialResolutionWithinRequest(): void
    {
        $decryptions = 0;
        $provider = new DatabaseCredentialProvider(
            function (string $value) use (&$decryptions): string {
                ++$decryptions;

                return $value;
            },
        );
        $registry = new CredentialProviderRegistry([$provider]);
        $account = ['id' => 42, 'username' => 'mailbox@example.test', 'password' => 'secret'];
        $context = new CredentialContext(CredentialPurpose::Imap);

        $first = $registry->getCredentials($account, $context);
        $second = $registry->getCredentials($account, $context);

        self::assertSame($first, $second);
        self::assertSame(1, $decryptions);
    }

    public function testFailsClosedForUnknownProvider(): void
    {
        $registry = new CredentialProviderRegistry([]);

        $this->expectException(ProviderNotFoundException::class);
        $registry->getCredentials(
            ['credential_provider' => 'unknown', 'id' => 1],
            new CredentialContext(CredentialPurpose::Imap),
        );
    }

    public function testManagedCredentialUsernamesMustMatchExpectedMailbox(): void
    {
        $registry = new CredentialProviderRegistry([
            new DatabaseCredentialProvider(static fn (string $value): string => $value),
        ]);

        try {
            $registry->getCredentials(
                [
                    'id' => 42,
                    'username' => 'mailbox@example.test',
                    'password' => 'secret',
                    'smtp_username' => 'other@example.test',
                ],
                new CredentialContext(
                    CredentialPurpose::Imap,
                    expectedMailbox: 'mailbox@example.test',
                ),
            );
            self::fail('A credential username outside the assignment must be rejected');
        } catch (InvalidAccountException $exception) {
            self::assertSame('credential_username_mismatch', $exception->errorCode);
        }
    }

    public function testCachedCredentialsAreRevalidatedForEachMailboxAssignment(): void
    {
        $registry = new CredentialProviderRegistry([
            new DatabaseCredentialProvider(static fn (string $value): string => $value),
        ]);
        $account = [
            'credential_reference' => 'shared-secret-reference',
            'username' => 'first@example.test',
            'password' => 'secret',
        ];
        $registry->getCredentials(
            $account,
            new CredentialContext(CredentialPurpose::Imap, expectedMailbox: 'first@example.test'),
        );

        try {
            $registry->getCredentials(
                $account,
                new CredentialContext(CredentialPurpose::Imap, expectedMailbox: 'second@example.test'),
            );
            self::fail('Cached credentials must be rebound to every assignment context');
        } catch (InvalidAccountException $exception) {
            self::assertSame('credential_username_mismatch', $exception->errorCode);
        }
    }
}
