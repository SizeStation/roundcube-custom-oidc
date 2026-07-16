<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials\Exception;

enum CredentialFailureKind: string
{
    case Invalid = 'invalid';
    case Unavailable = 'unavailable';
    case Unauthorized = 'unauthorized';
}
