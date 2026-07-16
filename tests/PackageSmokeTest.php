<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests;

use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Credentials\Package;

final class PackageSmokeTest extends TestCase
{
    public function testSharedPackageIsAutoloadable(): void
    {
        self::assertSame('sizestation/roundcube-credentials', Package::NAME);
    }
}
