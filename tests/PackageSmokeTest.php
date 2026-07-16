<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests;

use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Credentials\CredentialPurpose;

final class PackageSmokeTest extends TestCase
{
    public function testSharedPackageIsAutoloadable(): void
    {
        self::assertSame('imap', CredentialPurpose::Imap->value);
    }
}
