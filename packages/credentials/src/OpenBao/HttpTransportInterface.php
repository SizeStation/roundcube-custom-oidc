<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials\OpenBao;

interface HttpTransportInterface
{
    /** @param array<string, string> $headers */
    public function get(string $url, array $headers, OpenBaoClientConfig $config): HttpResponse;
}
