<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials;

interface CredentialProviderInterface
{
    public function name(): string;

    /** @param array<string, mixed> $account */
    public function supports(array $account): bool;

    /** @param array<string, mixed> $account */
    public function getCredentials(array $account, CredentialContext $context): AccountCredentials;
}
