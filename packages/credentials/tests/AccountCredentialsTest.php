<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Credentials;

use LogicException;
use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Credentials\AccountCredentials;

final class AccountCredentialsTest extends TestCase
{
    public function testEraseMakesPasswordsUnavailable(): void
    {
        $credentials = new AccountCredentials('mailbox@example.test', 'secret');
        $credentials->erase();

        $this->expectException(LogicException::class);
        $credentials->imapPassword();
    }

    public function testRejectsEmptyUsername(): void
    {
        $this->expectException(LogicException::class);
        new AccountCredentials('', 'secret');
    }
}
