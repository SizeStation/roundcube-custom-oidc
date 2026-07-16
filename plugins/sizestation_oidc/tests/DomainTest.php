<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Oidc;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Oidc\Domain\AssignmentInvariantException;
use SizeStation\Roundcube\Oidc\Domain\AssignmentSetValidator;
use SizeStation\Roundcube\Oidc\Domain\MailboxAddress;
use SizeStation\Roundcube\Oidc\Domain\OpaqueId;

final class DomainTest extends TestCase
{
    public function testNormalizesMailboxAddressConsistently(): void
    {
        self::assertSame('user@example.test', (string) new MailboxAddress(' User@Example.Test '));
    }

    public function testRejectsInvalidMailboxAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MailboxAddress('not-an-address');
    }

    public function testGeneratesUuidV4AssignmentIdentifier(): void
    {
        $id = OpaqueId::generate();

        self::assertSame((string) $id, (string) new OpaqueId((string) $id));
        self::assertMatchesRegularExpression('/-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-/', (string) $id);
    }

    public function testRequiresExactlyOneEnabledAnchor(): void
    {
        $validator = new AssignmentSetValidator();

        $this->expectException(AssignmentInvariantException::class);
        $validator->validateForLogin([
            ['enabled' => 1, 'is_anchor' => 0, 'is_preferred' => 0],
        ]);
    }

    public function testAllowsOneAnchorAndOnePreferredSecondary(): void
    {
        $validator = new AssignmentSetValidator();
        $validator->validateForLogin([
            ['enabled' => 1, 'is_anchor' => 1, 'is_preferred' => 0],
            ['enabled' => 1, 'is_anchor' => 0, 'is_preferred' => 1],
        ]);

        self::assertSame('anchor', $validator->anchorGuard(true, true));
        self::assertSame('preferred', $validator->preferredGuard(true, true));
        self::assertNull($validator->anchorGuard(true, false));
    }
}
