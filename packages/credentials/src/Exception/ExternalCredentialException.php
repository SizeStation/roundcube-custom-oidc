<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials\Exception;

final class ExternalCredentialException extends CredentialException
{
    public function __construct(
        string $errorCode,
        public readonly CredentialFailureKind $kind,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($errorCode, 'The managed mailbox credential is unavailable.', $previous);
    }
}
