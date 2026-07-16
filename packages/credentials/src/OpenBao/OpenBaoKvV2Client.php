<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials\OpenBao;

use JsonException;
use SizeStation\Roundcube\Credentials\Exception\CredentialFailureKind;
use SizeStation\Roundcube\Credentials\Exception\ExternalCredentialException;
use Throwable;

final class OpenBaoKvV2Client
{
    public function __construct(
        private readonly OpenBaoClientConfig $config,
        private readonly HttpTransportInterface $transport = new CurlHttpTransport(),
    ) {
    }

    /** @return array<string, mixed> */
    public function read(CredentialReference $reference): array
    {
        $url = $this->config->address
            . '/v1/' . rawurlencode($this->config->kvMount)
            . '/data/' . $this->encodedBasePath()
            . '/' . $reference->encodedPath();

        $response = $this->request($url);
        if ($response->status === 403) {
            $response = $this->request($url);
        }

        return $this->parseResponse($response);
    }

    private function request(string $url): HttpResponse
    {
        $token = $this->readToken();

        try {
            return $this->transport->get($url, [
                'Accept' => 'application/json',
                'X-Vault-Token' => $token,
            ], $this->config);
        } catch (ExternalCredentialException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ExternalCredentialException(
                'openbao_unavailable',
                CredentialFailureKind::Unavailable,
                $exception,
            );
        } finally {
            $this->erase($token);
        }
    }

    private function readToken(): string
    {
        $token = @file_get_contents($this->config->tokenFile);
        if (!is_string($token)) {
            throw new ExternalCredentialException(
                'openbao_token_unavailable',
                CredentialFailureKind::Unavailable,
            );
        }

        $token = trim($token);
        if ($token === '' || preg_match('/[\x00-\x1F\x7F]/', $token)) {
            $this->erase($token);
            throw new ExternalCredentialException(
                'openbao_token_invalid',
                CredentialFailureKind::Unavailable,
            );
        }

        return $token;
    }

    /** @return array<string, mixed> */
    private function parseResponse(HttpResponse $response): array
    {
        if ($response->status === 403) {
            throw new ExternalCredentialException(
                'openbao_forbidden',
                CredentialFailureKind::Unauthorized,
            );
        }

        if ($response->status === 404) {
            throw new ExternalCredentialException(
                'openbao_secret_missing',
                CredentialFailureKind::Invalid,
            );
        }

        if ($response->status < 200 || $response->status >= 300) {
            throw new ExternalCredentialException(
                'openbao_unavailable',
                CredentialFailureKind::Unavailable,
            );
        }

        try {
            $payload = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ExternalCredentialException(
                'openbao_response_invalid',
                CredentialFailureKind::Unavailable,
                $exception,
            );
        }

        $secret = $payload['data']['data'] ?? null;
        if (!is_array($secret)) {
            throw new ExternalCredentialException(
                'openbao_response_invalid',
                CredentialFailureKind::Unavailable,
            );
        }

        return $secret;
    }

    private function encodedBasePath(): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $this->config->basePath)));
    }

    private function erase(?string &$value): void
    {
        if ($value !== null && function_exists('sodium_memzero')) {
            sodium_memzero($value);
        }

        $value = null;
    }
}
