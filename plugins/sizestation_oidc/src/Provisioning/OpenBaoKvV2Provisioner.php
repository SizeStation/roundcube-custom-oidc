<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Provisioning;

use JsonException;
use RuntimeException;
use SizeStation\Roundcube\Credentials\OpenBao\CredentialReference;
use SizeStation\Roundcube\Credentials\OpenBao\OpenBaoClientConfig;

final class OpenBaoKvV2Provisioner implements SecretProvisionerInterface
{
    public function __construct(
        private readonly OpenBaoClientConfig $config,
        private readonly ProvisioningTransportInterface $transport = new CurlProvisioningTransport(),
    ) {
    }

    public function write(CredentialReference $reference, array $secret): void
    {
        if (
            !isset($secret['username'], $secret['password'])
            || $secret['username'] === ''
            || $secret['password'] === ''
        ) {
            throw new RuntimeException('Provisioned mailbox credentials are incomplete');
        }
        try {
            $body = json_encode(['data' => $secret], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the mailbox secret', 0, $exception);
        }
        try {
            $this->send('POST', $this->dataUrl($reference), $body, [200, 204]);
        } finally {
            $this->erase($body);
        }
    }

    public function delete(CredentialReference $reference): void
    {
        $this->send('DELETE', $this->metadataUrl($reference), null, [200, 204, 404]);
    }

    /** @param list<int> $successStatuses */
    private function send(string $method, string $url, ?string $body, array $successStatuses): void
    {
        $token = @file_get_contents($this->config->tokenFile);
        if (!is_string($token) || trim($token) === '') {
            throw new RuntimeException('OpenBao provisioning token is unavailable');
        }
        $token = trim($token);
        try {
            $response = $this->transport->request($method, $url, [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Vault-Token' => $token,
            ], $body, $this->config);
            if (!in_array($response->status, $successStatuses, true)) {
                throw new RuntimeException('OpenBao provisioning request was rejected');
            }
        } finally {
            $this->erase($token);
            $this->erase($body);
        }
    }

    private function dataUrl(CredentialReference $reference): string
    {
        return $this->baseUrl('data', $reference);
    }

    private function metadataUrl(CredentialReference $reference): string
    {
        return $this->baseUrl('metadata', $reference);
    }

    private function baseUrl(string $kind, CredentialReference $reference): string
    {
        $basePath = implode('/', array_map('rawurlencode', explode('/', $this->config->basePath)));

        return $this->config->address . '/v1/' . rawurlencode($this->config->kvMount)
            . '/' . $kind . '/' . $basePath . '/' . $reference->encodedPath();
    }

    private function erase(?string &$value): void
    {
        if ($value !== null && function_exists('sodium_memzero')) {
            sodium_memzero($value);
        }
        $value = null;
    }
}
