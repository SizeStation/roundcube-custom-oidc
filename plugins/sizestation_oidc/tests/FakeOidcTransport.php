<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Tests\Oidc;

use SizeStation\Roundcube\Oidc\Oidc\OidcClientConfig;
use SizeStation\Roundcube\Oidc\Oidc\OidcHttpResponse;
use SizeStation\Roundcube\Oidc\Oidc\OidcHttpTransportInterface;

final class FakeOidcTransport implements OidcHttpTransportInterface
{
    public string $tokenRequestBody = '';

    /** @param array<string, mixed> $metadata
     *  @param array<string, mixed> $jwks
     */
    public function __construct(
        private readonly array $metadata,
        private readonly array $jwks,
        public string $idToken,
    ) {
    }

    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        OidcClientConfig $config,
    ): OidcHttpResponse {
        if (str_ends_with($url, '/.well-known/openid-configuration')) {
            return new OidcHttpResponse(200, json_encode($this->metadata, JSON_THROW_ON_ERROR));
        }
        if ($url === 'https://issuer.example/token') {
            $this->tokenRequestBody = (string) $body;

            return new OidcHttpResponse(200, json_encode(['id_token' => $this->idToken], JSON_THROW_ON_ERROR));
        }
        if ($url === 'https://issuer.example/jwks') {
            return new OidcHttpResponse(200, json_encode($this->jwks, JSON_THROW_ON_ERROR));
        }

        return new OidcHttpResponse(404, '{}');
    }
}
