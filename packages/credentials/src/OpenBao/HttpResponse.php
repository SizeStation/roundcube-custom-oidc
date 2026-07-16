<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials\OpenBao;

final readonly class HttpResponse
{
    public function __construct(
        public int $status,
        public string $body,
    ) {
    }
}
