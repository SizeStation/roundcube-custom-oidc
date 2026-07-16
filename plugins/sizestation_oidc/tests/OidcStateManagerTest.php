<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Oidc;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SizeStation\Roundcube\Oidc\Security\OidcStateManager;

final class OidcStateManagerTest extends TestCase
{
    public function testCreatesPkceS256AndConsumesStateOnce(): void
    {
        $session = [];
        $manager = new OidcStateManager();
        $created = $manager->create($session, 1_000);

        self::assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $created->codeVerifier, true)), '+/', '-_'), '='),
            $created->codeChallenge,
        );
        self::assertSame(
            ['nonce' => $created->nonce, 'code_verifier' => $created->codeVerifier],
            $manager->consume($session, $created->state, 300, 1_100),
        );

        $this->expectException(RuntimeException::class);
        $manager->consume($session, $created->state, 300, 1_100);
    }

    public function testMismatchedStateIsDestroyed(): void
    {
        $session = [];
        $manager = new OidcStateManager();
        $created = $manager->create($session, 1_000);

        try {
            $manager->consume($session, $created->state . 'tampered', 300, 1_100);
            self::fail('Expected invalid state');
        } catch (RuntimeException) {
            self::assertSame([], $session);
        }
    }

    public function testRejectsExpiredState(): void
    {
        $session = [];
        $manager = new OidcStateManager();
        $created = $manager->create($session, 1_000);

        $this->expectException(RuntimeException::class);
        $manager->consume($session, $created->state, 300, 1_301);
    }
}
