# Authentik provider configuration

These values assume Authentik at `auth.sizestation.cloud`, the application slug
`roundcube`, and Roundcube at `mail.sizestation.cloud`. Change all three values
together if your deployment differs.

## Provider and application

In **Applications → Applications**, create an application with an
**OAuth2/OIDC** provider:

- client type: **Confidential**;
- signing key: an active RSA key; Roundcube allows `RS256` by default;
- issuer mode: per-provider;
- subject mode: `user_uuid` or another immutable mode;
- include claims in ID token: enabled;
- redirect URI comparison: **Strict**;
- redirect URI:
  `https://mail.sizestation.cloud/?_task=login&_action=plugin.sizestation_oidc.callback`;
- flows: Authorization Code; do not enable implicit flow for this client;
- scopes: `openid`, `profile`, `email`, and `sizestation_user_id`;
- refresh/offline access: not required.

The configured Roundcube issuer must exactly equal:

```text
https://auth.sizestation.cloud/application/o/roundcube/
```

Roundcube discovers the authorization, token, JWKS, and end-session endpoints
from that issuer. Copy the client ID into the stack environment. Store the
client secret only at `kv/roundcube/oidc` as `client_secret`; the Agent
renders it into tmpfs.

## Stable pre-provisioning claim

Create an OAuth2/OpenID **Scope Mapping** under **Customization → Property
Mappings**:

```text
Name: SizeStation stable user ID
Scope name: sizestation_user_id
Expression:
return {"sizestation_user_id": str(request.user.uuid)}
```

Select that mapping on the Roundcube provider. Use the exact resulting UUID as
the CLI `--external-user-id`. Treat it as an opaque value. Do not use email,
username, display name, or group slug as the binding key.

Before production, use Authentik's provider preview or decode a test ID token
locally and confirm that `sizestation_user_id` is a non-empty stable string.
Never paste a production token into an online decoder.

## Group authorization

The default `profile` mapping normally supplies a `groups` claim. To require
membership, set for example:

```php
$config['sizestation_oidc.allowed_groups'] = ['roundcube-users'];
$config['sizestation_oidc.groups_claim'] = 'groups';
```

The plugin checks this server-side. Authentik application visibility alone is
not the authorization boundary. Verify the exact group claim in a test token
after Authentik upgrades because customized profile mappings can change it.

## Acceptance check

Open a private browser window at `https://mail.sizestation.cloud/`. Confirm the
password form is hidden, OIDC redirects only to Authentik, and callback returns
to the exact configured URI. A user without an assignment must be denied rather
than shown the Roundcube password form.
