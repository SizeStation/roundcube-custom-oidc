<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Oidc;

use RuntimeException;

final class CurlOidcHttpTransport implements OidcHttpTransportInterface
{
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        OidcClientConfig $config,
    ): OidcHttpResponse {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the OIDC HTTP client');
        }

        try {
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
                CURLOPT_CONNECTTIMEOUT => $config->connectTimeoutSeconds,
                CURLOPT_TIMEOUT => $config->requestTimeoutSeconds,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            ];
            if ($config->caFile !== '') {
                $options[CURLOPT_CAINFO] = $config->caFile;
            }
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($handle, $options);

            $response = curl_exec($handle);
            if (!is_string($response)) {
                throw new RuntimeException('OIDC HTTP request failed');
            }

            return new OidcHttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $response);
        } finally {
            curl_close($handle);
        }
    }

    /** @param array<string, string> $headers
     *  @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        return $formatted;
    }
}
