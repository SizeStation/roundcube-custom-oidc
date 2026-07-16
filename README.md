# SizeStation Roundcube OIDC

This single Composer package adds Authentik sign-in and OpenBao-managed Purelymail
credentials to Roundcube 1.7. It extends the upstream `ident_switch` 5.0.4
plugin instead of replacing its multi-account switching.

The browser authenticates the person with OIDC. Roundcube then retrieves the
assigned mailbox app password from OpenBao and performs ordinary TLS IMAP/SMTP
authentication; Authentik tokens are never sent to Purelymail.

## Documentation

- [Architecture](docs/architecture.md)
- [Production deployment](docs/deployment.md)
- [Authentik configuration](docs/authentik.md)
- [Provisioning and operations](docs/operations.md)
- [Security, risks, and limitations](docs/security.md)
- [`ident_switch` fork changes](docs/ident-switch-upstream-diff.md)
- [Verification report](docs/verification.md)

Start with `docs/deployment.md`. Example assets in `deployment/` contain
placeholders only and must not be deployed before replacing them.

## Development verification

```sh
composer install
composer test
composer lint
sh tests/install-suite.sh
```

Production uses the official Roundcube image with
`ROUNDCUBEMAIL_COMPOSER_PLUGINS=sizestation/roundcube-oidc-suite:VERSION`.
The supplied post-setup task installs both bundled plugin trees and their CLI.
The Dockerfile remains an optional CI/offline fallback, not the primary deploy.

## Licence and source availability

The SizeStation distribution is licensed under AGPL-3.0-or-later. The retained
upstream plugin notices and complete GNU AGPL text are in
`plugins/ident_switch/LICENSE`. Anyone interacting with a modified deployed
version over a network must be offered the corresponding source for that exact
package release. Publish the immutable Git commit/tag and source archive; see
`SOURCE-AVAILABILITY.md`.
