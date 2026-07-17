<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Provisioning;

use InvalidArgumentException;

final readonly class OpenBaoAppRoleConfig
{
    public string $roleId;
    public string $secretId;
    public string $mountPath;

    public function __construct(string $roleId, string $secretId, string $mountPath = 'approle')
    {
        $this->roleId = trim($roleId);
        $this->secretId = trim($secretId);
        $this->mountPath = trim($mountPath, '/');

        if ($this->roleId === '' || $this->secretId === '') {
            throw new InvalidArgumentException('OpenBao AppRole credentials are incomplete');
        }
        if (
            $this->mountPath === ''
            || preg_match('#^[A-Za-z0-9][A-Za-z0-9_/-]*$#', $this->mountPath) !== 1
            || str_contains($this->mountPath, '//')
        ) {
            throw new InvalidArgumentException('OpenBao AppRole mount path is invalid');
        }
    }
}
