<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Domain;

use InvalidArgumentException;

final readonly class MailboxAddress
{
    public string $value;

    public function __construct(string $address)
    {
        $normalized = strtolower(trim($address));
        if (strlen($normalized) > 254 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Mailbox address is invalid');
        }

        $this->value = $normalized;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
