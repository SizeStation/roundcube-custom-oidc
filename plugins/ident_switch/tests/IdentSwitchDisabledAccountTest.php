<?php

declare(strict_types=1);

namespace {
    if (!class_exists('rcube_utils', false)) {
        final class rcube_utils
        {
            public const INPUT_POST = 1;

            public static function get_input_value(string $name, int $source, bool $allowHtml = false): ?string
            {
                $value = $_POST[$name] ?? null;

                return is_string($value) ? $value : null;
            }
        }
    }

    if (!class_exists('rcmail', false)) {
        final class rcmail
        {
            private static self $instance;
            public object $user;
            public object $session;
            public object $output;

            public function __construct(public rcube_db $db, public object $config)
            {
                $this->user = (object) ['ID' => 10, 'data' => ['username' => 'anchor@example.test']];
                $this->session = new class {
                    public function remove(string $key): void
                    {
                        unset($_SESSION[$key]);
                    }
                };
                $this->output = new class {
                    /** @var list<array{0: string, 1: mixed}> */
                    public array $commands = [];

                    public function redirect(array $target): void
                    {
                    }

                    public function show_message(string $message, string $type): void
                    {
                    }

                    public function command(string $command, mixed $data): void
                    {
                        $this->commands[] = [$command, $data];
                    }
                };
                self::$instance = $this;
            }

            public static function get_instance(): self
            {
                return self::$instance;
            }

            public function decrypt(string $password): string
            {
                return $password;
            }

            public function encrypt(string $password): string
            {
                return 'encrypted:' . $password;
            }
        }
    }

    if (!class_exists('rcube_storage', false)) {
        final class rcube_storage
        {
            /** @var list<string> */
            public static array $folder_types = ['drafts', 'sent', 'junk', 'trash'];
        }
    }

    if (!class_exists('rcube_imap_generic', false)) {
        final class rcube_imap_generic
        {
            public static int $connections = 0;
            public string $error = '';

            public function connect(string $host, string $username, string $password, array $options): bool
            {
                ++self::$connections;

                return true;
            }

            /** @return array{UNSEEN: int} */
            public function status(string $mailbox, array $items): array
            {
                return ['UNSEEN' => 0];
            }

            public function closeConnection(): void
            {
            }
        }
    }

    if (!class_exists('ident_switch', false)) {
        final class ident_switch
        {
            public const TABLE = 'ident_switch';
            public const DB_ENABLED = 1;
            public const DB_SECURE_IMAP_TLS = 2;
            public const MY_POSTFIX = '_ident_switch';
            public const SMTP_AUTH_NONE = 0;
            public const SMTP_AUTH_IMAP = 1;
            public const SMTP_AUTH_CUSTOM = 2;
            public const SIEVE_AUTH_NONE = 0;
            public const SIEVE_AUTH_IMAP = 1;
            public const SIEVE_AUTH_CUSTOM = 2;
            public const NOTIFY_CHECK_ENABLED = 1;

            public static function debug_log(string $message): void
            {
            }

            public static function write_log(string $message): void
            {
            }

            public static function resolve_username(int $identityId, ?string $username): string
            {
                return $username ?? '';
            }

            public static function ntrim(?string $value): ?string
            {
                $value = trim((string) $value);

                return $value === '' ? null : $value;
            }

            /** @return array{scheme: string, host: string} */
            public static function parse_host_scheme(string $host): array
            {
                if (str_contains($host, '://')) {
                    [$scheme, $hostname] = explode('://', $host, 2);

                    return ['scheme' => $scheme, 'host' => $hostname];
                }

                return ['scheme' => '', 'host' => $host];
            }

            public function add_texts(string $directory): void
            {
            }
        }
    }

    require_once dirname(__DIR__) . '/lib/IdentSwitchCredentialService.php';
    require_once dirname(__DIR__) . '/lib/IdentSwitchSwitcher.php';
    require_once dirname(__DIR__) . '/lib/IdentSwitchForm.php';
    require_once dirname(__DIR__) . '/lib/IdentSwitchChecker.php';
}

namespace SizeStation\Roundcube\Tests\IdentSwitch {
    use PDO;
    use PHPUnit\Framework\Attributes\RequiresPhpExtension;
    use PHPUnit\Framework\TestCase;

    #[RequiresPhpExtension('pdo_sqlite')]
    final class IdentSwitchDisabledAccountTest extends TestCase
    {
        private \rcmail $roundcube;

        protected function setUp(): void
        {
            $_SESSION = [];
            $_POST = [];
            \rcube_imap_generic::$connections = 0;
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE system (name varchar(64) primary key, value text)');
            $pdo->exec('CREATE TABLE identities (identity_id integer primary key, user_id integer, email text)');
            $pdo->exec(file_get_contents(dirname(__DIR__) . '/SQL/sqlite.initial.sql'));
            $config = new class {
                /** @var array<string, mixed> */
                public array $values = [];

                public function get(string $key, mixed $default = null): mixed
                {
                    return $this->values[$key] ?? $default;
                }
            };
            $this->roundcube = new \rcmail(new \rcube_db($pdo), $config);
            $this->insertAccount(1, 101, 0, 0);
        }

        public function testCredentialLookupRejectsDisabledAccount(): void
        {
            $service = new \IdentSwitchCredentialService($this->roundcube);

            self::assertNull($service->accountByIdentity(101));
            self::assertNotNull($service->accountByIdentity(101, false));
        }

        public function testCraftedSwitchRequestRejectsDisabledAccount(): void
        {
            $switcher = new \IdentSwitchSwitcher(new \IdentSwitchCredentialService($this->roundcube));

            self::assertFalse($switcher->switchAccountById(1, false));
            self::assertSame([], $_SESSION);
        }

        public function testSmtpAndSieveKeepTrustedDefaultsForDisabledAccount(): void
        {
            $_SESSION['iid' . \ident_switch::MY_POSTFIX] = 101;
            $switcher = new \IdentSwitchSwitcher(new \IdentSwitchCredentialService($this->roundcube));
            $smtp = ['smtp_host' => 'trusted', 'smtp_user' => 'anchor'];
            $sieve = ['host' => 'trusted', 'user' => 'anchor'];

            self::assertSame($smtp, $switcher->configure_smtp($smtp));
            self::assertSame($sieve, $switcher->configure_managesieve($sieve));
        }

        public function testDisabledAliasParentCannotSupplySmtpOrSieveCredentials(): void
        {
            $this->insertAccount(2, 102, 1, 1);
            $this->roundcube->db->query('UPDATE ident_switch SET parent_id = ? WHERE id = ?', 1, 2);
            $_SESSION['iid' . \ident_switch::MY_POSTFIX] = 102;
            $switcher = new \IdentSwitchSwitcher(new \IdentSwitchCredentialService($this->roundcube));
            $smtp = ['smtp_host' => 'trusted', 'smtp_user' => 'anchor'];
            $sieve = ['host' => 'trusted', 'user' => 'anchor'];

            self::assertSame($smtp, $switcher->configure_smtp($smtp));
            self::assertSame($sieve, $switcher->configure_managesieve($sieve));
        }

        public function testCraftedSwitchCannotSelectAnotherUsersEnabledAccount(): void
        {
            $this->insertAccount(2, 102, 1, 0, 11);
            $_SESSION = ['username' => 'anchor@example.test', 'sentinel' => 'unchanged'];
            $before = $_SESSION;
            $switcher = new \IdentSwitchSwitcher(new \IdentSwitchCredentialService($this->roundcube));

            self::assertFalse($switcher->switchAccountById(2, false));
            self::assertSame($before, $_SESSION);
        }

        public function testSuccessfulSecondarySwitchAndMailboxOnlyReturnPreserveOidcSession(): void
        {
            $this->insertAccount(2, 102, 1, 0);
            $_SESSION = [
                'username' => 'anchor@example.test',
                'password' => 'encrypted-anchor-secret',
                'storage_host' => 'imap.anchor.example',
                'storage_port' => 993,
                'storage_ssl' => 'ssl',
                'imap_delimiter' => '/',
                'sizestation_oidc.identity' => ['principal_id' => 77, 'subject' => 'subject-1'],
            ];
            $switcher = new \IdentSwitchSwitcher(new \IdentSwitchCredentialService($this->roundcube));

            self::assertTrue($switcher->switchAccountById(2, false));
            self::assertSame('mailbox@example.test', $_SESSION['username']);
            self::assertSame('encrypted:encrypted', $_SESSION['password']);
            self::assertSame(102, (int) $_SESSION['iid' . \ident_switch::MY_POSTFIX]);
            self::assertSame(77, $_SESSION['sizestation_oidc.identity']['principal_id']);

            self::assertTrue($switcher->switchAccountById(-1, false));
            self::assertSame('anchor@example.test', $_SESSION['username']);
            self::assertSame('encrypted-anchor-secret', $_SESSION['password']);
            self::assertSame('imap.anchor.example', $_SESSION['storage_host']);
            self::assertSame(-1, $_SESSION['iid' . \ident_switch::MY_POSTFIX]);
            self::assertSame(
                ['principal_id' => 77, 'subject' => 'subject-1'],
                $_SESSION['sizestation_oidc.identity'],
            );
        }

        public function testSecondaryCredentialFailureLeavesCurrentMailboxSessionUnchanged(): void
        {
            $this->insertAccount(2, 102, 1, 0);
            $this->roundcube->db->query(
                'UPDATE ident_switch SET credential_provider = ?, managed_externally = ? WHERE id = ?',
                'unconfigured-provider',
                1,
                2,
            );
            $_SESSION = [
                'username' => 'anchor@example.test',
                'password' => 'encrypted-anchor-secret',
                'storage_host' => 'imap.anchor.example',
                'sizestation_oidc.identity' => ['principal_id' => 77],
            ];
            $before = $_SESSION;
            $switcher = new \IdentSwitchSwitcher(new \IdentSwitchCredentialService($this->roundcube));

            self::assertFalse($switcher->switchAccountById(2, false));
            self::assertSame($before, $_SESSION);
        }

        public function testDisabledManagedAccountStillCannotBeDeletedFromSettings(): void
        {
            $this->roundcube->db->query(
                'UPDATE ident_switch SET managed_externally = ?, managed_assignment_id = ? WHERE id = ?',
                1,
                '00000000-0000-4000-8000-000000000001',
                1,
            );
            $form = new \IdentSwitchForm(
                new \ident_switch(),
                new \IdentSwitchCredentialService($this->roundcube),
            );

            $result = $form->on_identity_delete(['id' => 101]);

            self::assertTrue($result['abort']);
            self::assertFalse($result['result']);
            self::assertSame('ident_switch.err.managed', $result['message']);
            self::assertSame(1, (int) $this->roundcube->db->pdo->query(
                'SELECT COUNT(*) FROM ident_switch WHERE id = 1',
            )->fetchColumn());
        }

        public function testManagedOnlyModeRejectsCraftedSeparateAccountCreationWithHiddenFields(): void
        {
            $this->roundcube->config->values['ident_switch.managed_only'] = true;
            $_POST = [
                '_ident_switch_form_common_mode' => 'separate',
                '_ident_switch_form_imap_host' => 'ssl://attacker.example',
                '_ident_switch_form_smtp_host' => 'ssl://attacker.example',
                '_ident_switch_form_imap_pass' => 'browser-secret',
                '_ident_switch_form_credential_provider' => 'openbao',
                '_ident_switch_form_credential_reference' => '../../other-user',
            ];
            $form = new \IdentSwitchForm(
                new \ident_switch(),
                new \IdentSwitchCredentialService($this->roundcube),
            );

            $result = $form->on_identity_create(['record' => ['email' => 'other@example.test']]);

            self::assertTrue($result['abort']);
            self::assertFalse($result['result']);
            self::assertSame('ident_switch.err.managed_only', $result['message']);
            self::assertArrayNotHasKey('createData' . \ident_switch::MY_POSTFIX, $_SESSION);
        }

        public function testManagedOnlyModeRejectsEnabledLegacyAccountAtEveryConnectionHook(): void
        {
            $this->roundcube->config->values['ident_switch.managed_only'] = true;
            $this->roundcube->db->query(
                'UPDATE ident_switch SET flags = ?, imap_host = ?, smtp_host = ?, sieve_host = ? WHERE id = ?',
                1,
                'ssl://attacker.example',
                'ssl://attacker.example',
                'ssl://attacker.example',
                1,
            );
            $switcher = new \IdentSwitchSwitcher(new \IdentSwitchCredentialService($this->roundcube));
            $_SESSION = ['username' => 'anchor@example.test', 'sentinel' => 'unchanged'];
            $before = $_SESSION;

            self::assertFalse($switcher->switchAccountById(1, false));
            self::assertSame($before, $_SESSION);

            $_SESSION['iid' . \ident_switch::MY_POSTFIX] = 101;
            $smtp = ['smtp_host' => 'trusted', 'smtp_user' => 'anchor'];
            $sieve = ['host' => 'trusted', 'user' => 'anchor'];
            self::assertSame($smtp, $switcher->configure_smtp($smtp));
            self::assertSame($sieve, $switcher->configure_managesieve($sieve));
        }

        public function testManagedOnlyModeRejectsEditsToExistingLegacyAccount(): void
        {
            $this->roundcube->config->values['ident_switch.managed_only'] = true;
            $this->roundcube->db->query(
                'UPDATE ident_switch SET flags = ?, imap_host = ?, smtp_host = ? WHERE id = ?',
                1,
                'ssl://legacy-imap.example',
                'ssl://legacy-smtp.example',
                1,
            );
            $_POST = [
                '_ident_switch_form_common_mode' => 'separate',
                '_ident_switch_form_imap_host' => 'ssl://attacker.example',
                '_ident_switch_form_smtp_host' => 'ssl://attacker.example',
            ];
            $form = new \IdentSwitchForm(
                new \ident_switch(),
                new \IdentSwitchCredentialService($this->roundcube),
            );

            $result = $form->on_identity_update([
                'id' => 101,
                'record' => ['email' => 'legacy@example.test'],
            ]);

            self::assertTrue($result['abort']);
            self::assertFalse($result['result']);
            self::assertSame('ident_switch.err.managed_only', $result['message']);
            $hosts = $this->roundcube->db->pdo->query(
                'SELECT imap_host, smtp_host FROM ident_switch WHERE id = 1',
            )->fetch(PDO::FETCH_ASSOC);
            self::assertSame('ssl://legacy-imap.example', $hosts['imap_host']);
            self::assertSame('ssl://legacy-smtp.example', $hosts['smtp_host']);
        }

        public function testUnreadChecksAreRoundRobinAndRateLimitedPerSession(): void
        {
            $this->roundcube->config->values['ident_switch.round_robin'] = true;
            $this->roundcube->config->values['ident_switch.check_interval_seconds'] = 30;
            $this->roundcube->db->query(
                'UPDATE ident_switch SET flags = ?, notify_check = ? WHERE id = ?',
                1,
                1,
                1,
            );
            $this->insertAccount(2, 102, 1, 0);
            $this->roundcube->db->query('UPDATE ident_switch SET notify_check = ? WHERE id = ?', 1, 2);
            $this->roundcube->db->query(
                'INSERT INTO identities (identity_id, user_id, email) VALUES (?, ?, ?), (?, ?, ?)',
                101,
                10,
                'first@example.test',
                102,
                10,
                'second@example.test',
            );
            $_SESSION['username'] = 'anchor@example.test';
            $checker = new \IdentSwitchChecker(new \IdentSwitchCredentialService($this->roundcube));

            $checker->check_new_mail([]);
            self::assertSame(1, \rcube_imap_generic::$connections);

            $checker->check_new_mail([]);
            self::assertSame(1, \rcube_imap_generic::$connections);
            self::assertGreaterThan(0, $_SESSION['ident_switch_last_check_at']);
        }

        public function testCraftedManagedUpdateCannotChangeEmailProviderReferenceOrHosts(): void
        {
            $this->roundcube->db->query(
                'UPDATE ident_switch SET flags = 1, managed_externally = 1, credential_provider = ?,'
                . ' credential_reference = ?, imap_host = ?, smtp_host = ? WHERE id = 1',
                'openbao',
                'assignment/one',
                'ssl://trusted-imap.example',
                'ssl://trusted-smtp.example',
            );
            $this->roundcube->db->query(
                'INSERT INTO identities (identity_id, user_id, email) VALUES (?, ?, ?)',
                101,
                10,
                'managed@example.test',
            );
            $_POST = [
                '_ident_switch_form_common_mode' => 'primary',
                '_ident_switch_form_imap_host' => 'ssl://attacker.example',
                '_ident_switch_form_smtp_host' => 'ssl://attacker.example',
                '_ident_switch_form_credential_reference' => '../../other-user',
            ];
            $form = new \IdentSwitchForm(
                new \ident_switch(),
                new \IdentSwitchCredentialService($this->roundcube),
            );

            $result = $form->on_identity_update([
                'id' => 101,
                'record' => ['email' => 'attacker@example.test'],
            ]);

            self::assertSame('managed@example.test', $result['record']['email']);
            $account = $this->roundcube->db->pdo->query(
                'SELECT credential_provider, credential_reference, imap_host, smtp_host FROM ident_switch WHERE id = 1',
            )->fetch(PDO::FETCH_ASSOC);
            self::assertSame('openbao', $account['credential_provider']);
            self::assertSame('assignment/one', $account['credential_reference']);
            self::assertSame('ssl://trusted-imap.example', $account['imap_host']);
            self::assertSame('ssl://trusted-smtp.example', $account['smtp_host']);
        }

        private function insertAccount(
            int $id,
            int $identityId,
            int $flags,
            int $parentId,
            int $userId = 10,
        ): void
        {
            $this->roundcube->db->query(
                'INSERT INTO ident_switch (id, user_id, iid, parent_id, label, flags, username, password,'
                . ' smtp_auth, sieve_auth) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $id,
                $userId,
                $identityId,
                $parentId ?: null,
                'account-' . $id,
                $flags,
                'mailbox@example.test',
                'encrypted',
                \ident_switch::SMTP_AUTH_IMAP,
                \ident_switch::SIEVE_AUTH_IMAP,
            );
        }
    }
}
