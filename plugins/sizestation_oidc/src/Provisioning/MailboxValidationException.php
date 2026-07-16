<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Provisioning;

use RuntimeException;
use SizeStation\Roundcube\Credentials\Exception\CredentialFailureKind;
use Throwable;

final class MailboxValidationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly CredentialFailureKind $kind,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Mailbox credential validation failed', 0, $previous);
    }
}
