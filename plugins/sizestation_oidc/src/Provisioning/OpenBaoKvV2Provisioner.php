<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Provisioning;

use JsonException;
use RuntimeException;
use SizeStation\Roundcube\Credentials\OpenBao\CredentialReference;
use SizeStation\Roundcube\Credentials\OpenBao\OpenBaoClientConfig;

final class OpenBaoKvV2Provisioner implements SecretProvisionerInterface
{
    private ?string $appRoleToken = null;

    public function __construct(
        private readonly OpenBaoClientConfig $config,
        private readonly ProvisioningTransportInterface $transport = new CurlProvisioningTransport(),
        private readonly ?OpenBaoAppRoleConfig $appRole = null,
    ) {
    }

    public function __destruct()
    {
        $this->erase($this->appRoleToken);
    }

    public function create(CredentialReference $reference, array $secret): void
    {
        $this->writeSecret($reference, $secret, true);
    }

    public function write(CredentialReference $reference, array $secret): void
    {
        $this->writeSecret($reference, $secret, false);
    }

    /** @param array<string, string> $secret */
    private function writeSecret(CredentialReference $reference, array $secret, bool $createOnly): void
    {
        if (
            !isset($secret['username'], $secret['password'])
            || $secret['username'] === ''
            || $secret['password'] === ''
        ) {
            throw new RuntimeException('Provisioned mailbox credentials are incomplete');
        }
        try {
            $payload = ['data' => $secret];
            if ($createOnly) {
                $payload['options'] = ['cas' => 0];
            }
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
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
        $token = $this->provisioningToken();
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

    private function provisioningToken(): string
    {
        if ($this->appRole === null) {
            $token = @file_get_contents($this->config->tokenFile);
            if (!is_string($token) || trim($token) === '') {
                throw new RuntimeException('OpenBao provisioning token is unavailable');
            }

            return trim($token);
        }
        if ($this->appRoleToken !== null) {
            return $this->appRoleToken;
        }

        try {
            $body = json_encode([
                'role_id' => $this->appRole->roleId,
                'secret_id' => $this->appRole->secretId,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the OpenBao AppRole login', 0, $exception);
        }
        try {
            $response = $this->transport->request(
                'POST',
                $this->appRoleLoginUrl(),
                [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                $body,
                $this->config,
            );
            if ($response->status !== 200) {
                throw new RuntimeException('OpenBao AppRole login was rejected');
            }
            $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
            $token = $payload['auth']['client_token'] ?? null;
            if (!is_string($token) || trim($token) === '') {
                throw new RuntimeException('OpenBao AppRole login returned no client token');
            }
            $this->appRoleToken = trim($token);

            return $this->appRoleToken;
        } catch (JsonException $exception) {
            throw new RuntimeException('OpenBao AppRole login returned an invalid response', 0, $exception);
        } finally {
            $this->erase($body);
        }
    }

    private function appRoleLoginUrl(): string
    {
        $mountPath = implode('/', array_map('rawurlencode', explode('/', $this->appRole?->mountPath ?? '')));

        return $this->config->address . '/v1/auth/' . $mountPath . '/login';
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
