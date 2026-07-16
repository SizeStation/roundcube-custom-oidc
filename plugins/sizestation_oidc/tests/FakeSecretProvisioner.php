<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Oidc;

use SizeStation\Roundcube\Credentials\OpenBao\CredentialReference;
use SizeStation\Roundcube\Oidc\Provisioning\SecretProvisionerInterface;

final class FakeSecretProvisioner implements SecretProvisionerInterface
{
    /** @var array<string, array<string, string>> */
    public array $writes = [];
    /** @var list<string> */
    public array $deletes = [];
    public bool $throwAfterWrite = false;

    public function write(CredentialReference $reference, array $secret): void
    {
        $this->writes[$reference->value] = $secret;
        if ($this->throwAfterWrite) {
            throw new \RuntimeException('simulated provisioning failure');
        }
    }

    public function delete(CredentialReference $reference): void
    {
        $this->deletes[] = $reference->value;
    }
}
