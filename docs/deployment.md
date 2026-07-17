# Production deployment

The internal OpenBao listener is HTTP, which this code intentionally refuses.
The supplied configuration instead uses the existing publicly certified
`https://bao.sizestation.cloud` endpoint; bounded connectivity checks from both
relevant overlays succeeded. Do not change it back to the internal HTTP URL.

## 1. Prerequisites

- DNS and valid HTTPS for `mail.sizestation.cloud` and Authentik.
- OpenBao 2.5.x with TLS enabled and a KV v2 mount named `kv`.
- `public` and `openbao` external Swarm overlay networks.
- the existing `roundcube_db` volume and a single Roundcube replica while using
  SQLite;
- an Authentik provider configured as in `docs/authentik.md`;
- the container system CA bundle, which validates the public OpenBao certificate.

Apply `deployment/openbao/runtime-policy.hcl` to a renewable Agent AppRole. Set
its token use count to unlimited so Agent renewal works. Apply
`deployment/openbao/provisioning-policy.hcl` to a distinct administrative
identity whose short-lived token is mounted only in one-shot CLI containers.

The existing DES key remains at `kv/roundcube/des_key` with field `config`.
Do not overwrite it. Seed only the new OIDC client secret without putting it in
Git:

```sh
bao kv put -mount=kv roundcube/oidc \
  client_secret='REPLACE_WITH_AUTHENTIK_SECRET'
```

Roundcube's Authentik-side values are runtime configuration. The plugin's
versioned internal config accepts each non-secret value directly or from a
Docker-style file:

- `ROUNDCUBE_OIDC_ISSUER` or `ROUNDCUBE_OIDC_ISSUER_FILE`
- `ROUNDCUBE_OIDC_CLIENT_ID` or `ROUNDCUBE_OIDC_CLIENT_ID_FILE`
- `ROUNDCUBE_OIDC_REDIRECT_URI` or `ROUNDCUBE_OIDC_REDIRECT_URI_FILE`
- `ROUNDCUBE_OIDC_POST_LOGOUT_REDIRECT_URI` or
  `ROUNDCUBE_OIDC_POST_LOGOUT_REDIRECT_URI_FILE`
- `ROUNDCUBE_OIDC_CLIENT_SECRET_FILE` is the path to the secret file rendered
  by the Agent; the client secret itself is intentionally never accepted as an
  environment value.

If both a direct variable and its `_FILE` variant are set, startup fails closed.
These values configure the Roundcube OIDC client; the matching provider/client
must still exist in Authentik because an environment variable cannot create
server-side Authentik resources.

Changing the existing DES key invalidates Roundcube-encrypted data.
Create the external Swarm `roundcube_bao_role_id` and
`roundcube_bao_secret_id` secrets from the AppRole credentials.

## 2. Publish the single Composer package

The repository root is the single package
`sizestation/roundcube-oidc-suite`. It contains `sizestation_oidc`, the modified
`ident_switch`, their shared credential classes, CLI, migrations, licences, and
source notices. Register this public repository once on Packagist, then create
an immutable Git release tag. Packagist will expose that tag as the Composer
version; no separate packages or custom image registry are required.

Before tagging:

```sh
composer validate --strict
composer install --no-interaction
composer test
composer lint
git tag -s v1.0.0 -m 'SizeStation Roundcube OIDC suite 1.0.0'
git push origin v1.0.0
```

Set `ROUNDCUBEMAIL_COMPOSER_PLUGINS` to that exact package version in the stack.
The official Roundcube entrypoint invokes Composer. Because the package is type
`roundcube-plugin`, Roundcube's official plugin installer places it directly in
`plugins/roundcube_oidc_suite` and creates its config stub. The stack invokes
the package-owned `bin/start-roundcube-oidc` launcher through the official
entrypoint. That launcher uses Roundcube's database API to initialize the suite
schema on first install or apply incremental migrations on upgrades, after the
entrypoint has generated the database configuration, then starts Apache. This
ordering is required because the official image installs Composer plugins
before creating `config.docker.inc.php`. The plugin reads its custom environment
variables itself. There is no mounted Roundcube PHP config, separate post-setup
service, or custom image.

## 3. Back up and migrate

This does not introduce a separate database service. With the supplied stack,
"the database" is Roundcube's existing SQLite file in the `roundcube_db` volume
at `/var/roundcube/db`. The migrations add only the two plugins' tables and
columns to that existing Roundcube database. PostgreSQL and MariaDB migrations
are included for installations that already use those Roundcube backends.

Stop Roundcube, take a storage-level snapshot, and also copy the SQLite database
to a timestamped backup on the server. Confirm the backup is non-empty before
continuing. Do not migrate while the old container can write to SQLite.

The stack uses `stop-first` with one SQLite replica, so the previous Roundcube
task stops before Composer runs the plugin migrations. The initializers
record schema versions in Roundcube's `system` table and apply only required
changes. Test every package upgrade against a restored backup first.

## 4. Deploy

Validate before mutation:

```sh
docker stack config --compose-file deployment/stack.yml >/dev/null
docker stack deploy --compose-file deployment/stack.yml roundcube
```

The Agent and Roundcube share the same node-local RAM-backed volume, following
the existing OpenBao Agent deployment pattern. Only these services mount it;
rendered runtime files appear under `/run/secrets` read-only in Roundcube. The
official entrypoint consumes `roundcube_des_key`; the plugin consumes the OIDC
secret and renewable Agent token. The Agent reaches OpenBao on the `openbao` overlay while
Roundcube uses the configured TLS endpoint. Its runtime policy can read
configuration and mailbox credentials but cannot write or list them.

Check services and sanitized logs, then perform the acceptance checks in
`docs/verification.md`. Do not print or inspect rendered secret contents.

## Rollback

If deployment fails before any user login, restore the prior exact Composer
package version in the stack and restore the SQLite backup if migrations ran.

After production writes have occurred, do not overwrite the database casually:
that would lose new messages' local state, preferences, assignments, and audit
records. Stop the service, preserve the failed DB for investigation, assess the
delta, then restore or forward-fix. OpenBao KV v2 retains previous credential
versions, but restore a credential version only as a deliberate security action.

Do not remove the suite while managed sessions are active. The safe rollback
target is the previously tested package version plus its matching database
snapshot and config set.
