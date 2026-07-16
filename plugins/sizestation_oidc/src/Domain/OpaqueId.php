<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Domain;

use InvalidArgumentException;

final readonly class OpaqueId
{
    public string $value;

    public function __construct(string $value)
    {
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $value)) {
            throw new InvalidArgumentException('Opaque identifier is invalid');
        }

        $this->value = strtolower($value);
    }

    public static function generate(): self
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return new self(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        ));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
