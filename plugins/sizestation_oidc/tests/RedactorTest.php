<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Oidc;

use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Oidc\Security\Redactor;

final class RedactorTest extends TestCase
{
    public function testRecursivelyRedactsSensitiveMetadata(): void
    {
        $redacted = (new Redactor())->redact([
            'principal_id' => 42,
            'password' => 'mail-secret',
            'nested' => [
                'access_token' => 'oidc-secret',
                'error_code' => 'safe_code',
            ],
        ]);

        self::assertSame(42, $redacted['principal_id']);
        self::assertSame('[REDACTED]', $redacted['password']);
        self::assertSame('[REDACTED]', $redacted['nested']['access_token']);
        self::assertSame('safe_code', $redacted['nested']['error_code']);
    }
}
