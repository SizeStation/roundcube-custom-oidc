<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials\OpenBao;

use InvalidArgumentException;

final readonly class OpenBaoClientConfig
{
    public string $address;
    public string $kvMount;
    public string $basePath;

    public function __construct(
        string $address,
        public string $tokenFile,
        string $kvMount,
        string $basePath,
        public string $caFile,
        public int $connectTimeoutSeconds = 2,
        public int $requestTimeoutSeconds = 5,
    ) {
        $this->address = $this->validateAddress($address);
        $this->kvMount = $this->validatePath($kvMount, false, 'KV mount');
        $this->basePath = $this->validatePath($basePath, true, 'base path');

        if ($tokenFile === '' || $caFile === '') {
            throw new InvalidArgumentException('Token and CA files must be configured');
        }

        if ($connectTimeoutSeconds < 1 || $requestTimeoutSeconds < $connectTimeoutSeconds) {
            throw new InvalidArgumentException('OpenBao timeouts are invalid');
        }
    }

    private function validateAddress(string $address): string
    {
        $parts = parse_url($address);
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('OpenBao address must be an HTTPS origin');
        }

        $path = $parts['path'] ?? '';
        if ($path !== '' && $path !== '/') {
            throw new InvalidArgumentException('OpenBao address must not contain a path');
        }

        return rtrim($address, '/');
    }

    private function validatePath(string $path, bool $allowSlash, string $label): string
    {
        $pattern = $allowSlash
            ? '/\A[A-Za-z0-9][A-Za-z0-9._\/-]*[A-Za-z0-9]\z|\A[A-Za-z0-9]\z/'
            : '/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/';

        if (strlen($path) > 256 || !preg_match($pattern, $path) || str_contains($path, '..')) {
            throw new InvalidArgumentException($label . ' is invalid');
        }

        return $path;
    }
}
