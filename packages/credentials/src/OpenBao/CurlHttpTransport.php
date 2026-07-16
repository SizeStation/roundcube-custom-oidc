<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials\OpenBao;

use RuntimeException;

final class CurlHttpTransport implements HttpTransportInterface
{
    public function get(string $url, array $headers, OpenBaoClientConfig $config): HttpResponse
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the OpenBao HTTP client');
        }

        try {
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
                CURLOPT_CONNECTTIMEOUT => $config->connectTimeoutSeconds,
                CURLOPT_TIMEOUT => $config->requestTimeoutSeconds,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CAINFO => $config->caFile,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            ]);

            $body = curl_exec($handle);
            if (!is_string($body)) {
                throw new RuntimeException('OpenBao request failed');
            }

            return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body);
        } finally {
            curl_close($handle);
        }
    }

    /** @param array<string, string> $headers */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        return $formatted;
    }
}
