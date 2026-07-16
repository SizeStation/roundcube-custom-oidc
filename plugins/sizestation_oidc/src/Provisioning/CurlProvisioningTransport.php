<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Provisioning;

use RuntimeException;
use SizeStation\Roundcube\Credentials\OpenBao\HttpResponse;
use SizeStation\Roundcube\Credentials\OpenBao\OpenBaoClientConfig;

final class CurlProvisioningTransport implements ProvisioningTransportInterface
{
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        OpenBaoClientConfig $config,
    ): HttpResponse {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the OpenBao provisioning client');
        }
        try {
            $formatted = [];
            foreach ($headers as $name => $value) {
                $formatted[] = $name . ': ' . $value;
            }
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $formatted,
                CURLOPT_CONNECTTIMEOUT => $config->connectTimeoutSeconds,
                CURLOPT_TIMEOUT => $config->requestTimeoutSeconds,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CAINFO => $config->caFile,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            ];
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($handle, $options);
            $response = curl_exec($handle);
            if (!is_string($response)) {
                throw new RuntimeException('OpenBao provisioning request failed');
            }

            return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $response);
        } finally {
            curl_close($handle);
        }
    }
}
