<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Credentials;

use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Credentials\CredentialContext;
use SizeStation\Roundcube\Credentials\CredentialProviderRegistry;
use SizeStation\Roundcube\Credentials\CredentialPurpose;
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
}
