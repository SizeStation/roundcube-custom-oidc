<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials\Exception;

final class ProviderNotFoundException extends CredentialException
{
    public function __construct()
    {
        parent::__construct('credential_provider_not_found', 'The account credential provider is unavailable.');
    }
}
