<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Oidc;

final readonly class OidcHttpResponse
{
    public function __construct(public int $status, public string $body)
    {
    }
}
