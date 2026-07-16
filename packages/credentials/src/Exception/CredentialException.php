<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials\Exception;

use RuntimeException;

class CredentialException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $safeMessage,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($safeMessage, 0, $previous);
    }
}
