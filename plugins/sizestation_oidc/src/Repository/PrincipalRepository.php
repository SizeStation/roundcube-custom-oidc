<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Repository;

final class PrincipalRepository
{
    public function __construct(private readonly \rcube_db $database)
    {
    }

    /**
     * @param array{email?: string, preferred_username?: string, display_name?: string} $profile
     * @return array<string, mixed>
     */
    public function resolveOrCreate(
        string $issuer,
        string $subject,
        string $externalUserId,
        array $profile = [],
    ): array {
        $principal = $this->findBySubject($issuer, $subject);
        if ($principal !== null) {
            if (!hash_equals((string) $principal['external_user_id'], $externalUserId)) {
                throw new RepositoryException('OIDC principal external identifier mismatch');
            }

            return $principal;
        }

        $external = $this->findByExternalId($issuer, $externalUserId);
        if ($external !== null) {
            throw new RepositoryException('OIDC external identifier is already bound');
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $query = $this->database->query(
            'INSERT INTO ' . $this->database->table_name('sizestation_oidc_principals')
            . ' (issuer, subject, external_user_id, oidc_email, preferred_username, display_name,'
            . ' status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $issuer,
            $subject,
            $externalUserId,
            $profile['email'] ?? null,
            $profile['preferred_username'] ?? null,
            $profile['display_name'] ?? null,
            'pending',
            $now,
            $now,
        );

        if (!$query) {
            $principal = $this->findBySubject($issuer, $subject);
            if ($principal !== null && hash_equals((string) $principal['external_user_id'], $externalUserId)) {
                return $principal;
            }

            throw new RepositoryException('Unable to create the OIDC principal');
        }

        $principal = $this->findBySubject($issuer, $subject);
        if ($principal === null) {
            throw new RepositoryException('Created OIDC principal could not be loaded');
        }

        return $principal;
    }

    /** @return array<string, mixed>|null */
    public function findBySubject(string $issuer, string $subject): ?array
    {
        return $this->find('issuer = ? AND subject = ?', $issuer, $subject);
    }

    /** @return array<string, mixed>|null */
    public function findByExternalId(string $issuer, string $externalUserId): ?array
    {
        return $this->find('issuer = ? AND external_user_id = ?', $issuer, $externalUserId);
    }

    public function activate(int $principalId, int $roundcubeUserId): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $query = $this->database->query(
            'UPDATE ' . $this->database->table_name('sizestation_oidc_principals')
            . ' SET roundcube_user_id = ?, status = ?, first_login_at = COALESCE(first_login_at, ?),'
            . ' last_login_at = ?, updated_at = ? WHERE id = ? AND status != ?',
            $roundcubeUserId,
            'active',
            $now,
            $now,
            $now,
            $principalId,
            'disabled',
        );
        if (!$query || $this->database->affected_rows($query) !== 1) {
            throw new RepositoryException('Unable to activate the OIDC principal');
        }
    }

    /** @return array<string, mixed>|null */
    private function find(string $where, string ...$parameters): ?array
    {
        $query = $this->database->query(
            'SELECT * FROM ' . $this->database->table_name('sizestation_oidc_principals')
            . ' WHERE ' . $where,
            ...$parameters,
        );
        $row = $this->database->fetch_assoc($query);

        return is_array($row) ? $row : null;
    }
}
