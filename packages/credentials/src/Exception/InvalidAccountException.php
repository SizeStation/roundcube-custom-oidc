<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials\Exception;

final class InvalidAccountException extends CredentialException
{
    public function __construct(string $errorCode = 'credential_account_invalid')
    {
        parent::__construct($errorCode, 'The account credential configuration is invalid.');
    }
}
