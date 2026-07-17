# Provisioning and operations

Run administration on the single Swarm manager with the bundled host launcher.
It hides the one-shot Docker container, volume, network, and secret mounts:

```sh
chmod +x bin/roundcube-oidc-admin
bin/roundcube-oidc-admin help
```

First place a short-lived token carrying the `roundcube-provider` policy at
`/root/.secrets/openbao-provisioning-token` with mode `0600`. The launcher only
mounts it into one-shot administrative containers; the long-running web service
never receives it. Remove the token file after the administration window.

Use `--dry-run` before mutations. Set `ROUNDCUBE_OIDC_ADMIN_FORMAT=json` when
machine-readable output is useful. The full underlying CLI remains available
through `bin/roundcube-oidc-admin raw CLI_ARGUMENTS...`.

## Provision before first login

Obtain the immutable Authentik OIDC `sub` value. With `user_uuid` subject mode,
this is the Authentik user UUID. Provision exactly one anchor; the anchor is
permanent after first successful login.

```sh
bin/roundcube-oidc-admin --dry-run provision AUTHENTIK_SUB user@sizestation.com
bin/roundcube-oidc-admin provision AUTHENTIK_SUB user@sizestation.com
```

The launcher requests the Purelymail password through a hidden prompt and
generates an opaque credential reference automatically.

Add a secondary mailbox for the same Authentik subject. The launcher validates
IMAP and SMTP by default, writes OpenBao, creates the assignment, and never
prints the password:

```sh
bin/roundcube-oidc-admin add-email AUTHENTIK_SUB admin@sizestation.com
```

For an initialized principal, newly created assignments bind and reconcile
immediately; another OIDC login is not required.

## Rotate and validate

```sh
bin/roundcube-oidc-admin rotate ASSIGNMENT_UUID
bin/roundcube-oidc-admin validate ASSIGNMENT_UUID
```

Rotation writes a new KV v2 version. Existing requests may hold credentials
only for their current PHP request; the next request resolves the new version.

## Preferred, disable, remove, and principal controls

```sh
bin/roundcube-oidc-admin users
bin/roundcube-oidc-admin emails PRINCIPAL_ID
bin/roundcube-oidc-admin prefer ASSIGNMENT_UUID
bin/roundcube-oidc-admin disable-email ASSIGNMENT_UUID
bin/roundcube-oidc-admin remove-email ASSIGNMENT_UUID
bin/roundcube-oidc-admin purge-email ASSIGNMENT_UUID
bin/roundcube-oidc-admin disable-user PRINCIPAL_ID
```

Do not disable/remove the only enabled anchor. `remove-email` retires the
database assignment for auditability but retains its OpenBao credential.
`purge-email` also deletes that credential and requires confirmation. Disabling
a principal blocks new OIDC login but does not replace a full incident-response
session revocation procedure.

## Reconcile and audit

```sh
bin/roundcube-oidc-admin reconcile-user PRINCIPAL_ID
bin/roundcube-oidc-admin reconcile-all
bin/roundcube-oidc-admin audit PRINCIPAL_ID
```

Reconciliation is idempotent. It repairs missing managed identities and switch
rows, disables orphaned/disabled rows, and restores the preferred mapping. Run
it after assignment changes and after a partial materialization failure.

## Upgrade the fork

1. fetch the retained `upstream` remote and select an immutable upstream tag;
2. compare every password/credential call site listed in
   `docs/ident-switch-upstream-diff.md` against upstream changes;
3. merge/rebase in a dedicated branch without dropping upstream history;
4. update the baseline commit and change ledger;
5. run all distribution, managed/unmanaged regression, schema, image, and
   end-to-end tests;
6. publish a new source tag and pin its exact Composer package version;
7. rehearse migrations and rollback on a restored production backup.

Never point production directly at upstream `main`.
