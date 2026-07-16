<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials;

use LogicException;

final class AccountCredentials
{
    private ?string $imapPassword;
    private ?string $smtpPassword;
    private ?string $sievePassword;

    public function __construct(
        private readonly string $imapUsername,
        string $imapPassword,
        private readonly ?string $smtpUsername = null,
        ?string $smtpPassword = null,
        private readonly ?string $sieveUsername = null,
        ?string $sievePassword = null,
    ) {
        if ($imapUsername === '') {
            throw new LogicException('IMAP username must not be empty');
        }

        $this->imapPassword = $imapPassword;
        $this->smtpPassword = $smtpPassword;
        $this->sievePassword = $sievePassword;
    }

    public function imapUsername(): string
    {
        return $this->imapUsername;
    }

    public function imapPassword(): string
    {
        return $this->requireAvailable($this->imapPassword);
    }

    public function smtpUsername(): string
    {
        return $this->smtpUsername ?? $this->imapUsername;
    }

    public function smtpPassword(): string
    {
        return $this->smtpPassword === null
            ? $this->imapPassword()
            : $this->requireAvailable($this->smtpPassword);
    }

    public function sieveUsername(): string
    {
        return $this->sieveUsername ?? $this->imapUsername;
    }

    public function sievePassword(): string
    {
        return $this->sievePassword === null
            ? $this->imapPassword()
            : $this->requireAvailable($this->sievePassword);
    }

    public function erase(): void
    {
        $this->eraseString($this->imapPassword);
        $this->eraseString($this->smtpPassword);
        $this->eraseString($this->sievePassword);
    }

    public function __destruct()
    {
        $this->erase();
    }

    private function requireAvailable(?string $value): string
    {
        if ($value === null) {
            throw new LogicException('Credentials have been erased');
        }

        return $value;
    }

    private function eraseString(?string &$value): void
    {
        if ($value !== null && function_exists('sodium_memzero')) {
            sodium_memzero($value);
        }

        $value = null;
    }
}
