<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Oidc\Service;

use SizeStation\Roundcube\Credentials\AccountCredentials;
use SizeStation\Roundcube\Credentials\CredentialContext;
use SizeStation\Roundcube\Credentials\CredentialPurpose;
use SizeStation\Roundcube\Credentials\Provider\OpenBaoCredentialProvider;

final class AnchorCredentialResolver
{
    public function __construct(private readonly OpenBaoCredentialProvider $provider)
    {
    }

    /** @param array<string, mixed> $assignment */
    public function resolve(array $assignment, ?int $roundcubeUserId = null): AccountCredentials
    {
        $account = $assignment;
        $account['managed_externally'] = 1;
        if (!$this->provider->supports($account)) {
            throw new \RuntimeException('Anchor credential provider is not supported');
        }

        return $this->provider->getCredentials($account, new CredentialContext(
            CredentialPurpose::Imap,
            $roundcubeUserId,
            (string) $assignment['id'],
        ));
    }
}
