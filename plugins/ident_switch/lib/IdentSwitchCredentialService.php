<?php

/**
 * Generic credential-provider integration for ident_switch.
 *
 * Copyright (C) 2026 SizeStation
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use SizeStation\Roundcube\Credentials\AccountCredentials;
use SizeStation\Roundcube\Credentials\CredentialContext;
use SizeStation\Roundcube\Credentials\CredentialProviderRegistry;
use SizeStation\Roundcube\Credentials\CredentialPurpose;
use SizeStation\Roundcube\Credentials\OpenBao\OpenBaoClientConfig;
use SizeStation\Roundcube\Credentials\OpenBao\OpenBaoKvV2Client;
use SizeStation\Roundcube\Credentials\Provider\DatabaseCredentialProvider;
use SizeStation\Roundcube\Credentials\Provider\OpenBaoCredentialProvider;

final class IdentSwitchCredentialService
{
    public const ACCOUNT_FIELDS = 'id, user_id, iid, parent_id, username, password,'
        . ' smtp_username, smtp_password, sieve_username, sieve_password,'
        . ' credential_provider, credential_reference, managed_externally, managed_assignment_id';

    private CredentialProviderRegistry $registry;

    public function __construct(private readonly rcmail $rc)
    {
        $providers = [new DatabaseCredentialProvider([$rc, 'decrypt'])];
        $openBao = $this->openBaoProvider();
        if ($openBao !== null) {
            $providers[] = $openBao;
        }

        $this->registry = new CredentialProviderRegistry($providers);
    }

    /** @param array<string, mixed> $account */
    public function resolve(array $account, CredentialPurpose $purpose): AccountCredentials
    {
        return $this->registry->getCredentials($account, new CredentialContext(
            $purpose,
            $this->rc->user?->ID,
            $account['managed_assignment_id'] ?? null,
        ));
    }

    /** @return array<string, mixed>|null */
    public function accountByIdentity(int $identityId): ?array
    {
        $sql = 'SELECT ' . self::ACCOUNT_FIELDS . ' FROM '
            . $this->rc->db->table_name(ident_switch::TABLE)
            . ' WHERE iid = ? AND user_id = ? AND flags & ? > 0';
        $query = $this->rc->db->query(
            $sql,
            $identityId,
            $this->rc->user->ID,
            ident_switch::DB_ENABLED,
        );
        $row = $this->rc->db->fetch_assoc($query);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed>|null $account */
    public function isManaged(?array $account): bool
    {
        return !empty($account['managed_externally']);
    }

    public function clear(): void
    {
        $this->registry->clear();
    }

    private function openBaoProvider(): ?OpenBaoCredentialProvider
    {
        $config = $this->rc->config;
        $values = [
            'address' => $config->get('ident_switch.openbao_address'),
            'token_file' => $config->get('ident_switch.openbao_token_file'),
            'kv_mount' => $config->get('ident_switch.openbao_kv_mount'),
            'base_path' => $config->get('ident_switch.openbao_base_path'),
            'ca_file' => $config->get('ident_switch.openbao_ca_file'),
        ];

        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                return null;
            }
        }

        try {
            $clientConfig = new OpenBaoClientConfig(
                $values['address'],
                $values['token_file'],
                $values['kv_mount'],
                $values['base_path'],
                $values['ca_file'],
                (int) $config->get('ident_switch.openbao_connect_timeout_seconds', 2),
                (int) $config->get('ident_switch.openbao_request_timeout_seconds', 5),
            );
        } catch (\InvalidArgumentException) {
            ident_switch::write_log('OpenBao credential provider configuration is invalid.');

            return null;
        }

        return new OpenBaoCredentialProvider(new OpenBaoKvV2Client($clientConfig));
    }
}
