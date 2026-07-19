<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Oidc;

use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Oidc\Security\RequestSecurityPolicy;

final class RequestSecurityPolicyTest extends TestCase
{
    public function testRejectsAuthenticationWithoutValidatedOidcLoginPhase(): void
    {
        $result = (new RequestSecurityPolicy())->rejectNonOidcAuthentication([
            'valid' => true,
            'user' => 'anchor@example.test',
            'pass' => 'stolen-imap-password',
        ]);

        self::assertFalse($result['valid']);
        self::assertTrue($result['abort']);
    }

    public function testLogoutPreparationRequiresAValidRoundcubeRequestToken(): void
    {
        $policy = new RequestSecurityPolicy();

        self::assertFalse($policy->mayPrepareLogout(false));
        self::assertTrue($policy->mayPrepareLogout(true));
    }

    public function testAuthenticatedRoundcubeSessionRequiresOidcIdentity(): void
    {
        $policy = new RequestSecurityPolicy();

        self::assertFalse($policy->establishedSessionMayLackOidcIdentity(true));
        self::assertTrue($policy->establishedSessionMayLackOidcIdentity(false));
    }

    public function testOidcCallbackDestroysTheAnonymousSessionIdBeforeLogin(): void
    {
        $session = new class {
            public ?bool $destroyed = null;

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function regenerate_id(bool $destroy): bool
            {
                $this->destroyed = $destroy;

                return true;
            }
        };

        (new RequestSecurityPolicy())->destroyAnonymousSession($session);

        self::assertTrue($session->destroyed);
    }

    public function testOidcCallbackFailsClosedWhenSessionRotationFails(): void
    {
        $session = new class {
            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function regenerate_id(bool $destroy): bool
            {
                return false;
            }
        };

        $this->expectException(\RuntimeException::class);
        (new RequestSecurityPolicy())->destroyAnonymousSession($session);
    }

    public function testCallbackLimiterKeyIsBoundToTheRoundcubeSession(): void
    {
        $policy = new RequestSecurityPolicy();
        $first = $policy->callbackSourceKey('session-one', 'token-one', '10.0.0.8');
        $second = $policy->callbackSourceKey('session-two', 'token-two', '10.0.0.8');

        self::assertNotSame($first, $second);
        self::assertSame($first, $policy->callbackSourceKey('session-one', 'token-one', '10.0.0.8'));
        self::assertStringNotContainsString('session-one', $first);
        self::assertStringNotContainsString('token-one', $first);
    }
}
