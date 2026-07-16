<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Service;

use RuntimeException;
use SizeStation\Roundcube\Oidc\Domain\MailboxAddress;
use SizeStation\Roundcube\Oidc\Repository\PrincipalRepository;

final class RuntimeIdentityGuard
{
    public function __construct(
        private readonly PrincipalRepository $principals,
        private readonly \rcube_db $database,
    ) {
    }

    /** @param array<string, mixed> $identity
     *  @return array<string, mixed>
     */
    public function assertEstablishedSession(array $identity, int $roundcubeUserId): array
    {
        $principalId = (int) ($identity['principal_id'] ?? 0);
        if ($principalId <= 0 || $roundcubeUserId <= 0) {
            throw new RuntimeException('OIDC session identity is incomplete');
        }

        $principal = $this->principals->findById($principalId);
        if ($principal === null || (string) $principal['status'] !== 'active') {
            throw new RuntimeException('OIDC principal is not active');
        }

        $this->assertIdentityValue($principal, $identity, 'issuer');
        $this->assertIdentityValue($principal, $identity, 'subject');
        $this->assertIdentityValue($principal, $identity, 'external_user_id');

        if ((int) ($principal['roundcube_user_id'] ?? 0) !== $roundcubeUserId) {
            throw new RuntimeException('OIDC session is not mapped to the current Roundcube user');
        }

        return $principal;
    }

    /** @param array<string, mixed> $principal */
    public function assertAnchorMapping(
        array $principal,
        string $anchorMailbox,
        string $credentialUsername,
        string $configuredImapHost,
    ): void {
        $roundcubeUserId = (int) ($principal['roundcube_user_id'] ?? 0);
        if ($roundcubeUserId === 0) {
            return;
        }
        if ((string) ($principal['status'] ?? '') !== 'active') {
            throw new RuntimeException('Established OIDC principal is not active');
        }

        $anchor = (string) new MailboxAddress($anchorMailbox);
        $username = (string) new MailboxAddress($credentialUsername);
        if (!hash_equals($anchor, $username)) {
            throw new RuntimeException('Anchor credential username does not match its assignment');
        }

        $query = $this->database->query(
            'SELECT user_id, username, mail_host FROM ' . $this->database->table_name('users')
            . ' WHERE user_id = ?',
            $roundcubeUserId,
        );
        $user = $this->database->fetch_assoc($query);
        if (!is_array($user)) {
            throw new RuntimeException('Mapped Roundcube user no longer exists');
        }

        $storedUsername = (string) new MailboxAddress((string) ($user['username'] ?? ''));
        if (!hash_equals($username, $storedUsername)) {
            throw new RuntimeException('Mapped Roundcube user no longer matches the anchor mailbox');
        }

        $expectedHost = $this->normalizeHost($configuredImapHost);
        $storedHost = $this->normalizeHost((string) ($user['mail_host'] ?? ''));
        if ($expectedHost === '' || !hash_equals($expectedHost, $storedHost)) {
            throw new RuntimeException('Mapped Roundcube user no longer matches the anchor host');
        }
    }

    /** @param array<string, mixed> $principal
     *  @param array<string, mixed> $identity
     */
    private function assertIdentityValue(array $principal, array $identity, string $key): void
    {
        $expected = $principal[$key] ?? null;
        $actual = $identity[$key] ?? null;
        if (!is_string($expected) || !is_string($actual) || !hash_equals($expected, $actual)) {
            throw new RuntimeException('OIDC session identity does not match its principal');
        }
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }

        if (str_contains($host, '://')) {
            $parsed = parse_url($host, PHP_URL_HOST);

            return is_string($parsed) ? rtrim($parsed, '.') : '';
        }

        $withoutPort = preg_replace('/:\\d+$/', '', $host);

        return rtrim(is_string($withoutPort) ? $withoutPort : $host, '.');
    }
}
