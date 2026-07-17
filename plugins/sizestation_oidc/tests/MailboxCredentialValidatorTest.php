<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Oidc;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SizeStation\Roundcube\Oidc\Provisioning\MailboxCredentialValidator;

final class MailboxCredentialValidatorTest extends TestCase
{
    public function testSmtpStartTlsEndpointIsAccepted(): void
    {
        $validator = new MailboxCredentialValidator(
            'ssl://imap.example.test:993',
            'tcp://smtp.example.test:587',
        );

        self::assertInstanceOf(MailboxCredentialValidator::class, $validator);
    }

    public function testPlaintextImapEndpointIsRejected(): void
    {
        $this->expectException(RuntimeException::class);

        new MailboxCredentialValidator(
            'tcp://imap.example.test:143',
            'tcp://smtp.example.test:587',
        );
    }
}
