<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Credentials;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Credentials\Exception\InvalidAccountException;
use SizeStation\Roundcube\Credentials\OpenBao\CredentialReference;

final class CredentialReferenceTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function invalidReferences(): iterable
    {
        yield 'empty' => [''];
        yield 'absolute' => ['/mailbox'];
        yield 'parent traversal' => ['users/../admin'];
        yield 'encoded traversal' => ['users/%2e%2e/admin'];
        yield 'backslash' => ['users\\admin'];
        yield 'empty segment' => ['users//admin'];
        yield 'control character' => ["users/admin\nvalue"];
    }

    #[DataProvider('invalidReferences')]
    public function testRejectsUnsafeReferences(string $value): void
    {
        $this->expectException(InvalidAccountException::class);
        new CredentialReference($value);
    }

    public function testAcceptsOpaqueSegmentedReference(): void
    {
        $reference = new CredentialReference('users/01J0A0B1C2D3/assignment_123');

        self::assertSame('users/01J0A0B1C2D3/assignment_123', $reference->encodedPath());
    }
}
