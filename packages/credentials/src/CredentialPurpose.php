<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials;

enum CredentialPurpose: string
{
    case Imap = 'imap';
    case Smtp = 'smtp';
    case Sieve = 'sieve';
    case BackgroundCheck = 'background_check';
    case ConnectionTest = 'connection_test';
    case ProvisioningValidation = 'provisioning_validation';
}
