<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials;

final readonly class CredentialContext
{
    public function __construct(
        public CredentialPurpose $purpose,
        public ?int $roundcubeUserId = null,
        public ?string $assignmentId = null,
        public ?string $correlationId = null,
        public ?string $expectedMailbox = null,
    ) {
    }
}
