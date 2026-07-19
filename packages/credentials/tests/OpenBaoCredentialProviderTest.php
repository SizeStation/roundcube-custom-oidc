<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Credentials;

use PHPUnit\Framework\TestCase;
use SizeStation\Roundcube\Credentials\CredentialContext;
use SizeStation\Roundcube\Credentials\CredentialPurpose;
use SizeStation\Roundcube\Credentials\OpenBao\HttpResponse;
use SizeStation\Roundcube\Credentials\OpenBao\HttpTransportInterface;
use SizeStation\Roundcube\Credentials\OpenBao\OpenBaoClientConfig;
use SizeStation\Roundcube\Credentials\OpenBao\OpenBaoKvV2Client;
use SizeStation\Roundcube\Credentials\Provider\OpenBaoCredentialProvider;

final class OpenBaoCredentialProviderTest extends TestCase
{
    public function testResolvesManagedCredentialsAndProtocolFallbacks(): void
    {
        $tokenFile = tempnam(sys_get_temp_dir(), 'bao-token-');
        file_put_contents($tokenFile, 'token');
        $transport = new class implements HttpTransportInterface {
            public function get(string $url, array $headers, OpenBaoClientConfig $config): HttpResponse
            {
                return new HttpResponse(200, json_encode([
                    'data' => ['data' => [
                        'username' => 'mailbox@example.test',
                        'password' => 'app-password',
                    ]],
                ], JSON_THROW_ON_ERROR));
            }
        };
        $provider = new OpenBaoCredentialProvider(new OpenBaoKvV2Client(new OpenBaoClientConfig(
            'https://openbao:8200',
            $tokenFile,
            'secret',
            'roundcube/mailboxes',
            '/test/ca.pem',
        ), $transport));

        try {
            $credentials = $provider->getCredentials([
                'credential_provider' => 'openbao',
                'credential_reference' => 'assignment/1234',
                'managed_externally' => 1,
            ], new CredentialContext(
                CredentialPurpose::Imap,
                expectedMailbox: 'mailbox@example.test',
            ));

            self::assertSame('mailbox@example.test', $credentials->imapUsername());
            self::assertSame('app-password', $credentials->imapPassword());
            self::assertSame('mailbox@example.test', $credentials->smtpUsername());
            self::assertSame('app-password', $credentials->smtpPassword());
        } finally {
            @unlink($tokenFile);
        }
    }
}
