<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Security;

final class Redactor
{
    private const SENSITIVE_KEY = '/(?:pass(?:word)?|token|secret|authorization|auth_code|session(?:_id)?)/i';

    /** @param array<string, mixed> $metadata
     *  @return array<string, mixed>
     */
    public function redact(array $metadata): array
    {
        $result = [];
        foreach ($metadata as $key => $value) {
            if (preg_match(self::SENSITIVE_KEY, (string) $key)) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = $this->redact($value);
            } elseif (is_scalar($value) || $value === null) {
                $result[$key] = $value;
            } else {
                $result[$key] = '[UNSERIALIZABLE]';
            }
        }

        return $result;
    }
}
