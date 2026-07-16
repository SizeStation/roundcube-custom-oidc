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

Set `ROUNDCUBE_PROXY_WHITELIST` to the narrow CIDR used by the Traefik-facing
overlay (currently `10.0.1.0/24`). Roundcube trusts forwarded client addresses
only when the immediate peer is in this list. Recheck it whenever the overlay
network is recreated; never use `0.0.0.0/0`.

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

Changing the existing DES key invalidates Roundcube-encrypted data.
Create the external Swarm `roundcube_bao_role_id` and
`roundcube_bao_secret_id` secrets from the AppRole credentials.

## 2. Build and publish

From a clean tested checkout:

```sh
docker build --pull=false --build-arg VCS_REF=GIT_SHA \
  -t ghcr.io/sizestation/roundcube-custom-oidc:GIT_SHA .
docker push ghcr.io/sizestation/roundcube-custom-oidc:GIT_SHA
docker inspect --format '{{index .RepoDigests 0}}' \
  ghcr.io/sizestation/roundcube-custom-oidc:GIT_SHA
```

Replace the image placeholder in `deployment/stack.yml` with that digest and
the Authentik client-ID placeholder with the non-secret client ID. Never deploy
a moving tag.

## 3. Back up and migrate

Stop Roundcube, take a storage-level snapshot, and also copy the SQLite database
to a timestamped backup on the server. Confirm the backup is non-empty before
continuing. Do not migrate while the old container can write to SQLite.

Run two one-shot containers using the new image and existing DB volume. The
explicit entrypoint first installs/configures the pinned Roundcube tree, then
executes the requested plugin initializer:

```sh
docker run --rm --mount source=roundcube_db,target=/var/roundcube/db \
  --entrypoint /docker-entrypoint.sh -e ROUNDCUBEMAIL_DB_TYPE=sqlite \
  IMAGE_DIGEST bin/initdb.sh --dir=/var/www/html/plugins/ident_switch/SQL

docker run --rm --mount source=roundcube_db,target=/var/roundcube/db \
  --entrypoint /docker-entrypoint.sh -e ROUNDCUBEMAIL_DB_TYPE=sqlite \
  IMAGE_DIGEST bin/initdb.sh --dir=/var/www/html/plugins/sizestation_oidc/SQL
```

This initializes both plugins on the current fresh live database. For later
plugin upgrades, inspect the current schema version and run only the ordered
vendor-specific files under each plugin's `SQL/<driver>/` directory. Test every
migration against a restored backup first; never rerun an already-applied
version blindly.

## 4. Deploy

Validate before mutation:

```sh
docker stack config --compose-file deployment/stack.yml >/dev/null
docker stack deploy --compose-file deployment/stack.yml roundcube
```

The Agent and Roundcube share a RAM-backed volume owned by UID/GID 33. Token and
rendered secret files are `0640`; the CA certificate is public configuration.
Roundcube joins `openbao` only to reach the TLS service. Its runtime policy can
read configuration and mailbox credentials but cannot write or list them.

Check services and sanitized logs, then perform the acceptance checks in
`docs/verification.md`. Do not print or inspect rendered secret contents.

## Rollback

If deployment fails before any user login, remove the stack, restore the prior
stack file/image digest, and restore the SQLite backup if migrations ran.

After production writes have occurred, do not overwrite the database casually:
that would lose new messages' local state, preferences, assignments, and audit
records. Stop the service, preserve the failed DB for investigation, assess the
delta, then restore or forward-fix. OpenBao KV v2 retains previous credential
versions, but restore a credential version only as a deliberate security action.

Do not roll back to stock Roundcube while plugin tables and managed sessions are
active. The safe rollback target is the previously tested custom image plus its
matching database snapshot and config set.
