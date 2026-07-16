<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Credentials;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Credentials\OpenBao\OpenBaoClientConfig;

final class OpenBaoClientConfigTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function invalidAddresses(): iterable
    {
        yield 'http' => ['http://openbao:8200'];
        yield 'credentials' => ['https://user:pass@openbao:8200'];
        yield 'path' => ['https://openbao:8200/v1'];
        yield 'query' => ['https://openbao:8200?target=other'];
    }

    #[DataProvider('invalidAddresses')]
    public function testRejectsUnsafeAddress(string $address): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OpenBaoClientConfig($address, '/token', 'secret', 'roundcube/mailboxes', '/ca');
    }

    public function testNormalizesTrustedConfiguration(): void
    {
        $config = new OpenBaoClientConfig(
            'https://openbao:8200/',
            '/token',
            'secret',
            'roundcube/mailboxes',
            '/ca',
        );

        self::assertSame('https://openbao:8200', $config->address);
        self::assertSame('secret', $config->kvMount);
        self::assertSame('roundcube/mailboxes', $config->basePath);
    }
}
