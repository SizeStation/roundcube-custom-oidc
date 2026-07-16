<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Oidc;

use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Oidc\Session\OidcSession;

final class OidcSessionTest extends TestCase
{
    public function testCompleteLogoutClearsAllOidcAndPendingSelectionState(): void
    {
        $session = [
            'sizestation_oidc.identity' => ['principal_id' => 7],
            'sizestation_oidc.authorization' => ['state' => 'pending'],
            'sizestation_oidc.used_codes' => ['hash' => 123],
            'sizestation_oidc.preferred_switch_id' => 42,
            'sizestation_oidc.account_selection_pending' => true,
            'roundcube_core_state' => 'preserved-until-core-logout',
        ];

        (new OidcSession())->clear($session);

        self::assertSame(['roundcube_core_state' => 'preserved-until-core-logout'], $session);
    }
}
