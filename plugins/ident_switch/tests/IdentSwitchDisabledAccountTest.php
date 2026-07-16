<?php

declare(strict_types=1);

namespace {
    if (!class_exists('rcmail', false)) {
        final class rcmail
        {
            private static self $instance;
            public object $user;

            public function __construct(public rcube_db $db, public object $config)
            {
                $this->user = (object) ['ID' => 10, 'data' => ['username' => 'anchor@example.test']];
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
        }
    }

    if (!class_exists('ident_switch', false)) {
        final class ident_switch
        {
            public const TABLE = 'ident_switch';
            public const DB_ENABLED = 1;
            public const MY_POSTFIX = '_ident_switch';
            public const SMTP_AUTH_NONE = 0;
            public const SMTP_AUTH_IMAP = 1;
            public const SMTP_AUTH_CUSTOM = 2;
            public const SIEVE_AUTH_NONE = 0;
            public const SIEVE_AUTH_IMAP = 1;
            public const SIEVE_AUTH_CUSTOM = 2;

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
        }
    }

    require_once dirname(__DIR__) . '/lib/IdentSwitchCredentialService.php';
    require_once dirname(__DIR__) . '/lib/IdentSwitchSwitcher.php';
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
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE system (name varchar(64) primary key, value text)');
            $pdo->exec(file_get_contents(dirname(__DIR__) . '/SQL/sqlite.initial.sql'));
            $config = new class {
                public function get(string $key, mixed $default = null): mixed
                {
                    return $default;
                }
            };
            $this->roundcube = new \rcmail(new \rcube_db($pdo), $config);
            $this->insertAccount(1, 101, 0, 0);
        }

        public function testCredentialLookupRejectsDisabledAccount(): void
        {
            $service = new \IdentSwitchCredentialService($this->roundcube);

            self::assertNull($service->accountByIdentity(101));
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

        private function insertAccount(int $id, int $identityId, int $flags, int $parentId): void
        {
            $this->roundcube->db->query(
                'INSERT INTO ident_switch (id, user_id, iid, parent_id, label, flags, username, password,'
                . ' smtp_auth, sieve_auth) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $id,
                10,
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
