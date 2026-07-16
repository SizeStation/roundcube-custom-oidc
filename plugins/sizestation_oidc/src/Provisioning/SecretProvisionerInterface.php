<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Provisioning;

use SizeStation\Roundcube\Credentials\OpenBao\CredentialReference;

interface SecretProvisionerInterface
{
    /** @param array<string, string> $secret */
    public function write(CredentialReference $reference, array $secret): void;

    public function delete(CredentialReference $reference): void;
}
