# Provisioning and operations

Run administration in a one-shot container using the same Roundcube database,
runtime CA/token for reads, and a separate short-lived provisioning token at
`/run/admin-secrets/openbao-provisioning-token`. Never add that token to the web
service or shell history. All examples pipe passwords on standard input; prefer
an interactive hidden prompt or secret manager rather than `printf` in real use.

Set `IMAGE` to the deployed immutable digest. Use `--dry-run --format json` first for
every mutation.

On the single Swarm manager, invoke the CLI in a one-shot container. First
write a short-lived provisioning token to a root-owned `0600` file outside the
repository. The general command is:

```sh
docker run --rm -i --network public \
  --mount source=roundcube_db,target=/var/roundcube/db \
  --mount source=roundcube_bao_files,target=/run/app-secrets,readonly \
  --mount type=bind,src="$PWD/deployment/roundcube-config.inc.php",dst=/var/roundcube/config/sizestation_oidc.php,readonly \
  --mount type=bind,src=/root/.secrets/openbao-provisioning-token,dst=/run/admin-secrets/openbao-provisioning-token,readonly \
  -e ROUNDCUBEMAIL_DB_TYPE=sqlite \
  -e ROUNDCUBE_OIDC_CLIENT_ID=AUTHENTIK_CLIENT_ID \
  --entrypoint /docker-entrypoint.sh "$IMAGE" \
  bin/sizestation-oidc COMMAND_AND_OPTIONS
```

Remove the token file immediately after the administration window. The
long-running web service never receives it. In the examples below,
`/var/www/html/bin/sizestation-oidc ...` denotes the `COMMAND_AND_OPTIONS` tail
inside that one-shot container.

## Provision before first login

Obtain the immutable Authentik UUID emitted as `sizestation_user_id`. Provision
exactly one anchor; the anchor is permanent after first successful login.

```sh
read -s MAIL_PASSWORD
printf '%s' "$MAIL_PASSWORD" | /var/www/html/bin/sizestation-oidc provision \
  --issuer https://auth.sizestation.cloud/application/o/roundcube/ \
  --external-user-id AUTHENTIK_UUID \
  --mailbox user@sizestation.com \
  --credential-reference AUTHENTIK_UUID/anchor \
  --password-stdin --anchor --preferred --json
unset MAIL_PASSWORD
```

Add a secondary mailbox with a unique reference and omit `--anchor`. Add
`--preferred` if it should open automatically after anchor login. The command
validates IMAP and SMTP by default, writes OpenBao, creates the assignment, and
never prints the password.

To reference a credential already created through the separate provisioning
identity, omit `--password-stdin`. The CLI reads and validates the existing
secret but never rewrites or deletes it:

```sh
/var/www/html/bin/sizestation-oidc assignment:create \
  --issuer https://auth.sizestation.cloud/application/o/roundcube/ \
  --external-user-id AUTHENTIK_UUID \
  --mailbox admin@sizestation.com \
  --credential-reference OPAQUE_RANDOM_REFERENCE \
  --format json
```

For an initialized principal, newly created assignments bind and reconcile
immediately; another OIDC login is not required.

## Rotate and validate

```sh
read -s MAIL_PASSWORD
printf '%s' "$MAIL_PASSWORD" | /var/www/html/bin/sizestation-oidc rotate \
  --assignment-id ASSIGNMENT_UUID --password-stdin --json
unset MAIL_PASSWORD
/var/www/html/bin/sizestation-oidc validate \
  --assignment-id ASSIGNMENT_UUID --json
```

Rotation writes a new KV v2 version. Existing requests may hold credentials
only for their current PHP request; the next request resolves the new version.

## Preferred, disable, remove, and principal controls

```sh
/var/www/html/bin/sizestation-oidc set-preferred --assignment-id UUID --json
/var/www/html/bin/sizestation-oidc disable --assignment-id UUID --json
/var/www/html/bin/sizestation-oidc remove --assignment-id UUID --json
/var/www/html/bin/sizestation-oidc disable-principal --principal-id ID --json
```

Do not disable/remove the only enabled anchor. `remove` retires the database
assignment for auditability; add `--delete-secret` only when intentional and
after confirming no rollback needs the secret. Disabling a principal blocks new
OIDC login but does not replace a full incident-response session revocation
procedure.

## Reconcile and audit

```sh
/var/www/html/bin/sizestation-oidc reconcile:user --principal-id ID --format json
/var/www/html/bin/sizestation-oidc reconcile:all --format json
/var/www/html/bin/sizestation-oidc audit:list --principal-id ID --limit 100 --format json
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
