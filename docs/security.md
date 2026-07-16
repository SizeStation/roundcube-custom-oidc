# Security posture, risks, and limitations

## Enforced boundaries

- OIDC uses discovery, Authorization Code, PKCE S256, random single-use state
  and nonce, global callback/code replay records, strict issuer/audience/`azp`
  and time validation, and an allow-list of signature algorithms.
- Managed mailbox hosts, usernames, assignment IDs, providers, and secret
  references come from server-side records and trusted config, never browser
  fields. Switch queries are scoped to the current Roundcube user.
- OpenBao requires HTTPS with an explicit CA, strict timeouts, validated relative
  KV references, no redirects, and one bounded token-reread retry on HTTP 403.
- Plaintext credentials are request-local only and are not persisted in plugin
  tables, sessions, caches, audit data, or normal/debug logs.
- The web runtime has read-only KV access; writes use a separate identity.

## Remaining limitations

- The custom test suite uses mocks/fakes for Authentik, OpenBao, IMAP, and SMTP.
  A staging acceptance run with real non-production services is still required
  before claiming full end-to-end production acceptance.
- SQLite supports the current single replica only. Use PostgreSQL/MySQL and
  database-native concurrency testing before scaling Roundcube horizontally.
- Existing Roundcube/PHP sessions cannot be centrally revoked by this v1 plugin.
  Disable the principal, terminate affected service sessions, and rotate secrets
  during an incident.
- Authentik front/back-channel initiated logout is not implemented. Local
  complete logout uses the discovered RP-initiated end-session endpoint; its
  exact effect on the global Authentik session depends on Authentik policy.
- Changing an initialized anchor is intentionally unsupported. It requires a
  separately planned data migration.
- Purelymail app-password creation is outside scope; administrators supply and
  rotate existing passwords.
- Managed Sieve credentials are supported by the provider abstraction, but the
  supplied Purelymail deployment leaves Sieve disabled until a trusted endpoint
  is configured and tested.
- The internal OpenBao listener remains plaintext. Roundcube and its Agent must
  use the existing public TLS endpoint; no insecure fallback exists.

## Operational security checks

Search logs and CLI output for test canaries, never real secrets. Verify tmpfs
ownership/modes from inside each container without reading file contents. Alert
on repeated callback failures, missing anchors, OpenBao forbidden/unavailable,
credential validation failure, materialization failure, and account-switch
failure. Keep clocks synchronized on Authentik, Roundcube, and OpenBao.
