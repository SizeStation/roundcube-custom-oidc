# Architecture and upstream audit

Status: phase 1 design baseline, 2026-07-16.

## Verified baselines

- Upstream `ident_switch`: tag `5.0.4`, commit `8f45736b1bd74679721cd311e9a2ed306d2395f2`.
- Live Roundcube: `1.7.2`, PHP `8.4.23`.
- Live image digest: `roundcube/roundcubemail:1.7.2-apache@sha256:76503fb00caf1cb0ee7731723d5bf31b492383b689d532fa943c70e885913687`.
- Live database: SQLite volume at `/var/roundcube/db`.
- Live Roundcube has no third-party `ident_switch` installation.

Production remains on the official Roundcube image and pins the suite's exact
Composer version. The suite is one normal `roundcube-plugin` package.

## Repository topology

This repository is the SizeStation distribution and fork because the requested
`SizeStation/roundcube-ident_switch` remote does not currently exist and the
deployment owner asked to use this existing repository. The upstream history is
an unsquashed ancestor and the `upstream` remote is retained. The fork stays in
`plugins/ident_switch`; SizeStation changes will be listed in
`docs/ident-switch-upstream-diff.md` and tagged with the complete distribution.

No Composer-installed dependency is edited in place. This repository is one
versioned Roundcube plugin; the standard installer places it directly in
`plugins/roundcube_oidc_suite`. Its one entrypoint composes the OIDC and account
switching internals without exposing separate plugins to deployment.

## Runtime trust boundaries

```text
browser -> Traefik -> Roundcube/sizestation_oidc -> Purelymail IMAP/SMTP
                         |                 |
                         |                 +-> ident_switch provider registry
                         +-> Authentik OIDC        |
                                                   +-> OpenBao KV v2
```

- Authentik authenticates the person only. Its tokens never reach Purelymail.
- Purelymail receives the selected mailbox address and app password through
  ordinary TLS-protected IMAP/SMTP authentication.
- Browser input never selects hosts, usernames, credential providers, or secret
  references for managed accounts.
- Roundcube receives a read-only OpenBao Agent token. Provisioning and rotation
  use a separate identity and policy outside the web runtime.
- Persisted managed-account records contain only an opaque assignment UUID,
  provider name, and validated relative credential reference.

## Upstream 5.0.4 audit

The plugin uses Roundcube identities as the visible account/alias records and
one `ident_switch` row per identity. `user_id` owns every row; `iid` references
the Roundcube identity; aliases point to an account row through `parent_id`.
Switching changes the active storage and credential values in the Roundcube
server-side session while retaining the primary account state under the
`_iswitch` suffix.

Registered integration points are `startup`, `render_page`, `refresh`,
`smtp_connect`, `managesieve_connect`, identity create/update/delete hooks,
compose identity filtering, preferences hooks, and the
`plugin.ident_switch.switch` action. The switch action already scopes its row
lookup by both account ID and current Roundcube user ID.

Credential consumers that must all use the provider registry:

| Path | Current behavior | Required change |
| --- | --- | --- |
| `lib/IdentSwitchSwitcher.php::switch_account()` | Selects encrypted IMAP password into the active session | Resolve provider credentials after ownership lookup; preserve the existing switching/session algorithm |
| `configure_smtp()` | Decrypts IMAP or custom SMTP password; follows alias `parent_id` | Resolve the parent account once, then select provider IMAP/SMTP credentials and trusted host |
| `configure_managesieve()` | Decrypts IMAP or custom Sieve password; follows aliases | Resolve optional provider Sieve credentials without changing alias behavior |
| `lib/IdentSwitchChecker.php::check_unseen()` | Decrypts every secondary password before background IMAP connect | Resolve through the request cache; sanitize provider failures and preserve the previous count |
| `lib/IdentSwitchForm.php` connection tests | Decrypts sentinel values or accepts submitted plaintext | Managed rows ignore submitted secrets/hosts and test provider credentials; unmanaged rows retain compatibility |
| `IdentSwitchForm.php` save/delete hooks | Encrypts passwords and permits row mutation | Enforce managed fields and deletion restrictions server-side, not only in rendered fields |

The schema exists for MySQL/MariaDB, PostgreSQL, and SQLite. Managed support will
add nullable `credential_provider`, `credential_reference`,
`managed_assignment_id`, and a non-null boolean/portable integer
`managed_externally`, with a unique managed assignment reference. Existing
password columns remain for unmanaged compatibility and are null for managed
accounts.

There is no upstream automated test suite in tag 5.0.4; only PHPCS is configured.
The fork therefore needs characterization tests around the current hooks before
provider refactoring, in addition to new provider and security tests.

## Component boundaries

`packages/credentials` is an internal namespaced module autoloaded by the single
suite package. It owns credential value objects, contexts, the provider interface
and registry, a request-local cache, OpenBao KV v2 client, reference validation,
error taxonomy, and redaction. It has no Authentik, Purelymail, Roundcube-user,
or SizeStation assignment policy.

`plugins/ident_switch` owns switching, aliases, secondary session state, SMTP,
Sieve, unread checks, managed UI enforcement, and its additive schema. It does
not perform OIDC or assignment reconciliation.

`plugins/sizestation_oidc` owns discovery/JWKS-backed OIDC, principal and
assignment tables, anchor login injection, audit, managed-account
materialization, reconciliation, preferred account selection, logout, and CLI.
It invokes the existing `ident_switch` switch service rather than duplicating it.

## OIDC and bootstrap invariants

- Authorization Code with PKCE S256, random single-use state and nonce, exact
  configured redirect URI, and a maintained JWT/OIDC library.
- Validate signature/algorithm, issuer, audience, `azp` where applicable,
  expiry, not-before, issued-at tolerance, nonce, and required group claims.
- Canonical principal is the exact validated `(issuer, subject)` pair. The
  standard OIDC `sub` value binds administrator-created assignments by default;
  a custom stable claim is an explicit compatibility override.
- Pending assignments bind atomically. Login fails closed unless exactly one
  enabled anchor exists.
- The authenticate hook accepts only the server-side pending anchor, fetches its
  secret, and supplies the fixed configured IMAP endpoint. It never trusts login
  form values.
- Materialization is idempotent by assignment UUID and never matches labels.
- The anchor differs only during the login handshake. Reconciliation otherwise
  materializes it as a normal managed identity with a metadata-only,
  non-switchable record so the account is not shown twice.
- Changing the anchor after first successful login is a separate migration
  operation.

## Cross-database constraints

Portable unique constraints cover `(issuer, subject)`, `(issuer,
external_user_id)`, assignment UUID, and managed assignment UUID. Exact-one
anchor and at-most-one preferred are protected by transactional repository
checks with row/database locking appropriate to each engine, plus unique guard
columns/indexes where supported. Concurrency tests are mandatory for first
binding and preferred changes. Application checks alone are not sufficient.
Credential references are intentionally reusable across assignments for the
same normalized mailbox; repository checks reject sharing one reference across
different mailbox addresses.

## Deployment decisions

1. Roundcube is attached only to `public` and uses the trusted public TLS
   OpenBao endpoint; the Agent retains the internal `openbao` network.
2. The Agent renders `roundcube_des_key`, the OIDC client-secret file, and a
   renewable runtime token sink into the shared tmpfs.
3. The shared tmpfs follows the operator's established Agent pattern. Limit its
   mounts to the Agent and Roundcube services and mount it read-only in Roundcube.
4. A minimal core Roundcube config declares the Traefik-terminated public origin
   as HTTPS and establishes host-only, `SameSite=Lax` session cookies before
   session initialization.
5. SMTP and provisioning validation use STARTTLS on port 587. The validator
   requires STARTTLS to be advertised and successfully negotiates TLS before
   sending credentials.
6. SQLite is suitable for the current single replica, but migrations and tests
   still cover all three claimed database families.

## Delivery sequence and commit gates

1. Import and audit upstream 5.0.4 (complete).
2. Add reproducible build and plugin/package test scaffolding.
3. Add generic provider abstraction, database provider, request cache, and
   compatibility tests.
4. Add OpenBao provider, TLS/token retry/path validation, error mapping, and
   redaction tests.
5. Add managed schema/UI enforcement and route every audited credential path.
6. Add OIDC portable schema, repositories, audit, and domain invariants.
7. Add OIDC authorization callback/session/logout security.
8. Add anchor authentication and first-login binding.
9. Add materialization, reconciliation, and preferred switching.
10. Add provisioning/validation/rotation/disable CLI with dry-run and JSON.
11. Add suite packaging, Agent, policies, Swarm, Authentik, migration, and rollback docs.
12. Run the full unit/database/integration/security suite and publish final risks.

Each gate is committed, tested in proportion to its scope, and pushed before the
next gate. Production deployment happens only after the final verification gate;
the live stack is inspection-only until then.
