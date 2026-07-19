<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Oidc;

use JsonException;
use RuntimeException;
use SizeStation\Roundcube\Oidc\Repository\CallbackSecurityRepository;
use SizeStation\Roundcube\Oidc\Security\IdTokenValidator;
use SizeStation\Roundcube\Oidc\Security\OidcStateManager;
use SizeStation\Roundcube\Oidc\Security\ValidatedIdentity;

final class OidcFlowService
{
    private const USED_CODES_KEY = 'sizestation_oidc.used_codes';

    public function __construct(
        private readonly OidcClientConfig $config,
        private readonly IdTokenValidator $validator,
        private readonly OidcStateManager $stateManager = new OidcStateManager(),
        private readonly OidcHttpTransportInterface $http = new CurlOidcHttpTransport(),
        private readonly ?CallbackSecurityRepository $callbackSecurity = null,
    ) {
    }

    /** @param array<string, mixed> $session */
    public function authorizationUrl(array &$session): string
    {
        $metadata = $this->metadata();
        $state = $this->stateManager->create($session);
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->config->redirectUri,
            'scope' => implode(' ', $this->config->scopes),
            'state' => $state->state,
            'nonce' => $state->nonce,
            'code_challenge' => $state->codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return $metadata->authorizationEndpoint . '?' . $query;
    }

    /** @param array<string, mixed> $session */
    public function complete(
        array &$session,
        string $state,
        string $code,
        string $source = 'unknown',
    ): ValidatedIdentity {
        if ($state === '' || $code === '' || strlen($state) > 1024 || strlen($code) > 4096) {
            throw new RuntimeException('OIDC callback parameters are invalid');
        }
        $pending = $this->stateManager->consume($session, $state);
        $this->callbackSecurity?->assertAttemptAllowed($source);
        if ($this->callbackSecurity !== null) {
            $this->callbackSecurity->claimAuthorizationCode($code);
        } else {
            $this->consumeCode($session, $code);
        }
        $metadata = $this->metadata();
        $clientSecret = $this->clientSecret();
        $tokenRequestBody = http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->config->redirectUri,
                'client_id' => $this->config->clientId,
                'client_secret' => $clientSecret,
                'code_verifier' => $pending['code_verifier'],
            ], '', '&', PHP_QUERY_RFC3986);
        try {
            $token = $this->requestJson(
                'POST',
                $metadata->tokenEndpoint,
                ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
                $tokenRequestBody,
            );
        } finally {
            $this->erase($clientSecret);
            $this->erase($tokenRequestBody);
        }
        $idToken = $token['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '') {
            throw new RuntimeException('OIDC token response did not include an ID token');
        }

        $jwks = $this->requestJson('GET', $metadata->jwksUri, ['Accept' => 'application/json'], null);

        return $this->validator->validate($idToken, $jwks, $pending['nonce']);
    }

    /** @param array<string, mixed> $session */
    public function rejectProviderError(array &$session, string $state, string $source = 'unknown'): void
    {
        $this->stateManager->consumeError($session, $state);
        $this->callbackSecurity?->assertAttemptAllowed($source);
    }

    public function endSessionUrl(string $postLogoutRedirectUri, ?string $idTokenHint = null): ?string
    {
        $this->assertSafePostLogoutRedirect($postLogoutRedirectUri);
        $metadata = $this->metadata();
        if ($metadata->endSessionEndpoint === null) {
            return null;
        }
        $query = ['post_logout_redirect_uri' => $postLogoutRedirectUri, 'client_id' => $this->config->clientId];
        if ($idTokenHint !== null && $idTokenHint !== '') {
            $query['id_token_hint'] = $idTokenHint;
        }

        return $metadata->endSessionEndpoint . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function assertSafePostLogoutRedirect(string $url): void
    {
        $redirect = parse_url($url);
        $callback = parse_url($this->config->redirectUri);
        if (
            !is_array($redirect)
            || !is_array($callback)
            || ($redirect['scheme'] ?? null) !== 'https'
            || !isset($redirect['host'], $callback['host'])
            || !hash_equals(strtolower((string) $callback['host']), strtolower((string) $redirect['host']))
            || (int) ($callback['port'] ?? 443) !== (int) ($redirect['port'] ?? 443)
            || isset($redirect['user'])
            || isset($redirect['pass'])
            || isset($redirect['fragment'])
        ) {
            throw new RuntimeException('OIDC post-logout redirect is not allow-listed');
        }
    }

    private function metadata(): ProviderMetadata
    {
        return ProviderMetadata::fromDocument(
            $this->requestJson('GET', $this->config->discoveryUrl(), ['Accept' => 'application/json'], null),
            $this->config->issuer,
        );
    }

    /** @param array<string, string> $headers
     *  @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, array $headers, ?string $body): array
    {
        $response = $this->http->request($method, $url, $headers, $body, $this->config);
        if ($response->status < 200 || $response->status >= 300 || strlen($response->body) > 1048576) {
            throw new RuntimeException('OIDC provider request failed');
        }
        try {
            $decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('OIDC provider returned invalid JSON', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('OIDC provider response is invalid');
        }

        return $decoded;
    }

    private function clientSecret(): string
    {
        $secret = @file_get_contents($this->config->clientSecretFile);
        if (!is_string($secret) || trim($secret) === '') {
            throw new RuntimeException('OIDC client secret is unavailable');
        }

        return trim($secret);
    }

    private function erase(string &$value): void
    {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($value);
        }
        $value = '';
    }

    /** @param array<string, mixed> $session */
    private function consumeCode(array &$session, string $code): void
    {
        $now = time();
        $hash = hash('sha256', $code);
        $used = is_array($session[self::USED_CODES_KEY] ?? null) ? $session[self::USED_CODES_KEY] : [];
        $used = array_filter($used, static fn (mixed $expires): bool => is_int($expires) && $expires >= $now);
        if (isset($used[$hash])) {
            throw new RuntimeException('OIDC authorization code has already been used');
        }
        $used[$hash] = $now + 600;
        $session[self::USED_CODES_KEY] = array_slice($used, -20, null, true);
    }
}
