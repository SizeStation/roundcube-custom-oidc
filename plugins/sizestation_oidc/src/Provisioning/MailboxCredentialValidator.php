<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Provisioning;

use RuntimeException;
use SizeStation\Roundcube\Credentials\AccountCredentials;
use SizeStation\Roundcube\Credentials\Exception\CredentialFailureKind;

final class MailboxCredentialValidator
{
    public function __construct(
        private readonly string $imapEndpoint,
        private readonly string $smtpEndpoint,
        private readonly int $timeoutSeconds = 10,
    ) {
        $this->parseEndpoint($imapEndpoint);
        $this->parseEndpoint($smtpEndpoint);
        if ($timeoutSeconds < 1 || $timeoutSeconds > 60) {
            throw new RuntimeException('Mailbox validation timeout is invalid');
        }
    }

    public function validate(AccountCredentials $credentials, bool $imap = true, bool $smtp = true): void
    {
        if ($imap) {
            $this->validateImap($credentials->imapUsername(), $credentials->imapPassword());
        }
        if ($smtp) {
            $this->validateSmtp($credentials->smtpUsername(), $credentials->smtpPassword());
        }
    }

    private function validateImap(string $username, string $password): void
    {
        $stream = $this->connect($this->imapEndpoint);
        $payload = '';
        try {
            $greeting = $this->readLine($stream);
            if (!str_starts_with($greeting, '* OK')) {
                throw new MailboxValidationException('imap_unavailable', CredentialFailureKind::Unavailable);
            }
            $payload = base64_encode("\0{$username}\0{$password}");
            $this->write($stream, "A001 AUTHENTICATE PLAIN {$payload}\r\n");
            $response = '';
            for ($line = 0; $line < 50; ++$line) {
                $response = $this->readLine($stream);
                if (str_starts_with($response, 'A001 ')) {
                    break;
                }
            }
            $this->write($stream, "A002 LOGOUT\r\n");
            if (!str_starts_with($response, 'A001 OK')) {
                throw new MailboxValidationException(
                    'imap_authentication_rejected',
                    CredentialFailureKind::Invalid,
                );
            }
        } finally {
            fclose($stream);
            $this->erase($payload);
            $this->erase($password);
        }
    }

    private function validateSmtp(string $username, string $password): void
    {
        $stream = $this->connect($this->smtpEndpoint);
        $payload = '';
        try {
            if (!$this->responseCode($this->readResponse($stream), 220)) {
                throw new MailboxValidationException('smtp_unavailable', CredentialFailureKind::Unavailable);
            }
            $this->write($stream, "EHLO roundcube.sizestation.invalid\r\n");
            if (!$this->responseCode($this->readResponse($stream), 250)) {
                throw new MailboxValidationException('smtp_unavailable', CredentialFailureKind::Unavailable);
            }
            $payload = base64_encode("\0{$username}\0{$password}");
            $this->write($stream, "AUTH PLAIN {$payload}\r\n");
            $authenticated = $this->responseCode($this->readResponse($stream), 235);
            $this->write($stream, "QUIT\r\n");
            if (!$authenticated) {
                throw new MailboxValidationException(
                    'smtp_authentication_rejected',
                    CredentialFailureKind::Invalid,
                );
            }
        } finally {
            fclose($stream);
            $this->erase($payload);
            $this->erase($password);
        }
    }

    /** @return resource */
    private function connect(string $endpoint)
    {
        [$host] = $this->parseEndpoint($endpoint);
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ]]);
        $errno = 0;
        $stream = @stream_socket_client(
            $endpoint,
            $errno,
            $error,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (!is_resource($stream)) {
            throw new MailboxValidationException('mailbox_endpoint_unavailable', CredentialFailureKind::Unavailable);
        }
        stream_set_timeout($stream, $this->timeoutSeconds);

        return $stream;
    }

    /** @return array{string, int} */
    private function parseEndpoint(string $endpoint): array
    {
        $parts = parse_url($endpoint);
        if (
            !is_array($parts) || ($parts['scheme'] ?? null) !== 'ssl' || empty($parts['host'])
            || !isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
        ) {
            throw new RuntimeException('Mailbox validation endpoint must use fixed implicit TLS');
        }

        return [(string) $parts['host'], (int) $parts['port']];
    }

    /** @param resource $stream */
    private function readLine($stream): string
    {
        $line = fgets($stream, 8192);
        if (!is_string($line)) {
            throw new MailboxValidationException('mailbox_connection_lost', CredentialFailureKind::Unavailable);
        }

        return rtrim($line, "\r\n");
    }

    /** @param resource $stream */
    private function readResponse($stream): string
    {
        $response = '';
        do {
            $line = $this->readLine($stream);
            $response .= $line . "\n";
        } while (isset($line[3]) && $line[3] === '-');

        return $response;
    }

    /** @param resource $stream */
    private function write($stream, string $command): void
    {
        if (fwrite($stream, $command) !== strlen($command)) {
            throw new MailboxValidationException('mailbox_connection_lost', CredentialFailureKind::Unavailable);
        }
    }

    private function responseCode(string $response, int $code): bool
    {
        return str_starts_with($response, (string) $code);
    }

    private function erase(string &$value): void
    {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($value);
        }
        $value = '';
    }
}
