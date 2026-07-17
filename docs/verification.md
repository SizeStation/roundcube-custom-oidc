# Verification report

Verified on 2026-07-17 against the official Roundcube 1.7.2 image. Production
remained untouched.

## Automated and build evidence

- PHPUnit: **116 tests, 351 assertions**, all passing from a clean locked install.
- PHPCS: **85 files**, all passing.
- Fresh SQLite plugin migration: Roundcube's official Composer installer
  reported `[OK]` and created both internal schemas from the combined SQL set.
- Fresh PostgreSQL 16 and MariaDB 11 schemas: both plugin schemas applied to
  isolated containers and recorded `ident_switch-version=2026071600` and
  `sizestation_oidc-version=2026071602`.
- The single `sizestation/roundcube-oidc-suite` package passed strict Composer
  validation and a clean locked install. Roundcube installed it directly at
  `plugins/roundcube_oidc_suite`; its one entrypoint loaded OIDC, shared
  credentials, account switching, and the packaged environment/`_FILE`
  configuration successfully. No copy script or custom image participated.
- A clean configured Roundcube Composer install invoked the package lifecycle,
  initialized the combined SQLite schema, and recorded all three package
  versions. The official-image deferred path registered and removed its
  post-setup migration task in a disposable lifecycle fixture without requiring
  a custom service command.
- `docker stack config` rendered the supplied Swarm file successfully with the
  intended native Roundcube secret path and no mounted PHP configuration.
- OpenBao Agent uses the operator's existing shared-tmpfs pattern and renders
  files readable by the Roundcube container; AppRole still controls OpenBao access.
- TLS `bao status` succeeded from both `public` and `openbao` overlays against
  `https://bao.sizestation.cloud`.

## Original-plan comparison

Phases 1–8 are implemented: audited upstream fork, generic/database/OpenBao
providers, all identified managed credential paths, managed UI enforcement,
OIDC anchor login/logout, portable schemas, reconciliation/preferred switching,
administrative CLI, single-package stock-image deployment, Agent/policies/Swarm, operations, rollback,
licensing, architecture, and upstream-diff documentation.

The final gap pass additionally verified disabled-account rejection in crafted
switch, SMTP, Sieve, alias-parent, and credential-lookup paths; atomic rollback
of principal activation, anchor initialization, and account materialization;
dedicated no-mailbox-assigned behavior; persistent sanitized anchor credential
failure states; OpenBao/materialization audit events; and a visible
corresponding-source link. These checks used only disposable containers and
volumes on the server.

The final requirements re-audit also added create-with-existing-secret support,
canonical CLI command/output names, immediate post-login assignment binding and
materialization, transactional fail-closed disable/remove behavior, automatic
return from a disabled active secondary mailbox, correct transient mailbox
validation classification during reconciliation, same-origin OIDC discovery
endpoints, a no-preference mailbox chooser that delegates to `ident_switch`,
and the simplified standard-plugin Swarm deployment.

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
3. register the single suite on Packagist, publish the exact tested tag, and
   replace the package-version/client-ID placeholders in the stack;
4. back up SQLite, rehearse restore, then run both migrations;
5. provision a non-production anchor and secondary mailbox, deploy to staging,
   and exercise the browser acceptance matrix;
6. only after staging passes, deploy production and repeat login, switching,
   SMTP, disable/rotation, reconciliation, mailbox-only return, and complete
   logout checks.

The remaining limitations and consciously out-of-scope items are recorded in
`docs/security.md`.
