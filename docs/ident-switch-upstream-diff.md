# ident_switch SizeStation changes

Upstream baseline: Gecka-Apps `ident_switch` 5.0.4
(`8f45736b1bd74679721cd311e9a2ed306d2395f2`).

This ledger will be updated with every fork change so upgrades and upstreamable
patches remain reviewable.

## Distribution scaffold

- Added an empty `tests` directory so the distribution-level PHPUnit suite can
  discover fork tests as they are introduced. No upstream runtime code changed.

## Shared credential-provider foundation

- Added the distribution-owned `sizestation/roundcube-credentials` package with
  a provider interface/registry, protocol-aware credential value object,
  request-local cache, sanitized error types, and legacy database provider.
- No `ident_switch` runtime path uses the registry in this commit. This isolates
  and tests legacy decryption semantics before the switching refactor.

## OpenBao provider

- Added a generic OpenBao KV v2 client and `openbao` provider to the shared
  package. It enforces an HTTPS origin, CA verification, strict timeouts,
  validated mount/base/reference paths, no redirects, and sanitized errors.
- The client reads the Agent token sink for each request. On a forbidden
  response it rereads and retries exactly once to handle token rotation.
- No upstream runtime code changed in this commit.

## Managed-account integration

- Added portable managed credential columns and the `2026071600` migration for
  SQLite, PostgreSQL, and MySQL/MariaDB. Legacy password columns remain intact
  for unmanaged accounts; managed rows leave them unused.
- Routed account switching, alias-aware SMTP, ManageSieve, background unread
  checks, and primary-account return state through the provider registry.
- Added request-local provider reuse and sanitized provider failure handling.
- Managed accounts use administrator-configured IMAP/SMTP/Sieve endpoints rather
  than row or browser-supplied hosts.
- Managed identity forms show an ownership notice and do not expose connection
  fields. Update/delete hooks enforce immutability server-side, and optional
  managed-only mode rejects direct POST attempts to create arbitrary accounts.
- The stock-container package uses Roundcube's normal `vendor/autoload.php`;
  `/opt/sizestation/vendor` remains only as an optional custom-image fallback.
