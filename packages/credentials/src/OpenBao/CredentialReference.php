<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials\OpenBao;

use SizeStation\Roundcube\Credentials\Exception\InvalidAccountException;

final readonly class CredentialReference
{
    public string $value;

    public function __construct(string $value)
    {
        if (
            $value === ''
            || strlen($value) > 512
            || str_starts_with($value, '/')
            || str_ends_with($value, '/')
            || str_contains($value, '\\')
            || str_contains($value, '%')
        ) {
            throw new InvalidAccountException('credential_reference_invalid');
        }

        foreach (explode('/', $value) as $segment) {
            if (
                $segment === '.'
                || $segment === '..'
                || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $segment)
            ) {
                throw new InvalidAccountException('credential_reference_invalid');
            }
        }

        $this->value = $value;
    }

    public function encodedPath(): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $this->value)));
    }
}
