<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Provisioning;

use SizeStation\Roundcube\Credentials\OpenBao\HttpResponse;
use SizeStation\Roundcube\Credentials\OpenBao\OpenBaoClientConfig;

interface ProvisioningTransportInterface
{
    /** @param array<string, string> $headers */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        OpenBaoClientConfig $config,
    ): HttpResponse;
}
