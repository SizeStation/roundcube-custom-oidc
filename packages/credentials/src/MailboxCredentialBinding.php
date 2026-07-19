<?php

declare(strict_types=1);

namespace SizeStation\Roundcube\Credentials;

use SizeStation\Roundcube\Credentials\Exception\InvalidAccountException;

final class MailboxCredentialBinding
{
    public static function validate(
        AccountCredentials $credentials,
        ?string $expectedMailbox,
    ): AccountCredentials {
        if ($expectedMailbox === null) {
            return $credentials;
        }

        $expected = strtolower(trim($expectedMailbox));
        if ($expected === '' || filter_var($expected, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidAccountException('credential_expected_mailbox_invalid');
        }

        foreach (
            [$credentials->imapUsername(), $credentials->smtpUsername(), $credentials->sieveUsername()] as $username
        ) {
            $username = strtolower(trim($username));
            if (filter_var($username, FILTER_VALIDATE_EMAIL) === false || !hash_equals($expected, $username)) {
                throw new InvalidAccountException('credential_username_mismatch');
            }
        }

        return $credentials;
    }
}
