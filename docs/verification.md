# Verification report

Verified on 2026-07-16 against Git commit `e6f73c4` and the pinned Roundcube
1.7.2 / PHP 8.4.23 base. Production remained untouched.

## Automated and build evidence

- PHPUnit: **74 tests, 161 assertions**, all passing.
- PHPCS: **77 files**, all passing.
- Fresh SQLite plugin migrations: both initializers reported `[OK]` on a
  disposable named volume using the documented entrypoint commands.
- Fresh PostgreSQL 16 and MariaDB 11 schemas: both plugin schemas applied to
  isolated containers and recorded `ident_switch-version=2026071600` and
  `sizestation_oidc-version=2026071601`.
- Custom image built successfully with both base images pinned by digest,
  Elastic2022 pinned by commit and archive checksum, production Composer
  dependencies installed at build time, licences included, OIDC autoload
  asserted, and CLI syntax asserted.
- Local verification image manifest-list digest:
  `sha256:2f1faf94e7384e8978a1bd591e892a4d8cf159076fa2bf8ed832dd95d2984690`.
  This is not a registry digest and must not be placed in the production stack.
- CLI help completed through the real Roundcube Docker entrypoint.
- `docker stack config` rendered the supplied Swarm file successfully with the
  intended runtime secret paths and config sources.
- OpenBao Agent HCL started as UID/GID 33 on the protected tmpfs, created its
  token sink as `0640`, and reached its auth loop; the test intentionally did
  not mount AppRole credentials.
- TLS `bao status` succeeded from both `public` and `openbao` overlays against
  `https://bao.sizestation.cloud`.

## Original-plan comparison

Phases 1–8 are implemented: audited upstream fork, generic/database/OpenBao
providers, all identified managed credential paths, managed UI enforcement,
OIDC anchor login/logout, portable schemas, reconciliation/preferred switching,
administrative CLI, pinned image, Agent/policies/Swarm, operations, rollback,
licensing, architecture, and upstream-diff documentation.

The final production end-to-end acceptance run is deliberately pending external
configuration. The Authentik discovery URL
`https://auth.sizestation.cloud/application/o/roundcube/.well-known/openid-configuration`
currently returns **404**, so no Roundcube OIDC provider/client exists yet.
The runtime/provisioning OpenBao policies, AppRoles, OIDC client secret, and real
mailbox app passwords also require an authorized OpenBao administrator. No
secret values were inspected or invented during verification.

Do not deploy until the checklist below is complete:

1. create the Authentik provider/mapping from `docs/authentik.md` and confirm its
   discovery endpoint returns 200;
2. apply both OpenBao policies, attach the runtime policy to the Roundcube Agent
   AppRole, create the separate provisioning identity, and store the OIDC secret;
3. build/push the exact final Git commit and replace both stack placeholders
   with the registry digest/client ID;
4. back up SQLite, rehearse restore, then run both migrations;
5. provision a non-production anchor and secondary mailbox, deploy to staging,
   and exercise the browser acceptance matrix;
6. only after staging passes, deploy production and repeat login, switching,
   SMTP, disable/rotation, reconciliation, mailbox-only return, and complete
   logout checks.

The remaining limitations and consciously out-of-scope items are recorded in
`docs/security.md`.
