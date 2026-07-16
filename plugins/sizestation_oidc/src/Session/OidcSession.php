<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Session;

use SizeStation\Roundcube\Oidc\Service\LoginPhase;

final class OidcSession
{
    private const KEY = 'sizestation_oidc.identity';

    /** @param array<string, mixed> $session */
    public function establish(array &$session, LoginPhase $phase): void
    {
        $session[self::KEY] = [
            'principal_id' => (int) $phase->principal['id'],
            'issuer' => $phase->identity->issuer,
            'subject' => $phase->identity->subject,
            'external_user_id' => $phase->identity->externalUserId,
            'authentication_time' => $phase->identity->authenticationTime,
        ];
    }

    /** @param array<string, mixed> $session
     *  @return array<string, mixed>|null
     */
    public function identity(array $session): ?array
    {
        $identity = $session[self::KEY] ?? null;

        return is_array($identity) ? $identity : null;
    }

    /** @param array<string, mixed> $session */
    public function clear(array &$session): void
    {
        unset($session[self::KEY], $session['sizestation_oidc.authorization'], $session['sizestation_oidc.used_codes']);
    }
}
