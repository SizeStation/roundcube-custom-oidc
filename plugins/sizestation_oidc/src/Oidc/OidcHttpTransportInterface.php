<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Oidc;

interface OidcHttpTransportInterface
{
    /** @param array<string, string> $headers */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        OidcClientConfig $config,
    ): OidcHttpResponse;
}
