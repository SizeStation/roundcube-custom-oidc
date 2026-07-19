<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Security;

final class RequestSecurityPolicy
{
    /** @param array<string, mixed> $args
     *  @return array<string, mixed>
     */
    public function rejectNonOidcAuthentication(array $args): array
    {
        $args['valid'] = false;
        $args['abort'] = true;

        return $args;
    }

    public function mayPrepareLogout(bool $requestTokenValid): bool
    {
        return $requestTokenValid;
    }

    public function establishedSessionMayLackOidcIdentity(bool $roundcubeAuthenticated): bool
    {
        return !$roundcubeAuthenticated;
    }

    public function destroyAnonymousSession(object $session): void
    {
        if ($session->regenerate_id(true) !== true) {
            throw new \RuntimeException('Unable to rotate the pre-login session');
        }
    }

    public function callbackSourceKey(string $sessionId, ?string $requestToken, ?string $sourceIp): string
    {
        $sessionId = trim($sessionId);
        $requestToken = trim((string) $requestToken);
        $sourceIp = trim((string) $sourceIp);

        if ($sessionId === '' && $requestToken === '') {
            return 'anonymous:' . hash('sha256', $sourceIp !== '' ? $sourceIp : 'unknown');
        }

        return 'session:' . hash('sha256', $sessionId . "\0" . $requestToken . "\0" . $sourceIp);
    }
}
