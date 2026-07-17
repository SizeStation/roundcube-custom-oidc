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
```

Production uses the official Roundcube image with
`ROUNDCUBEMAIL_COMPOSER_PLUGINS=sizestation/roundcube-oidc-suite:VERSION`.
The package is a standard `roundcube-plugin`; Roundcube's Composer installer
places it directly in `plugins/roundcube_oidc_suite`, creates its config stub,
and invokes its supported install/update hook. On a configured Roundcube that
hook applies the database schema immediately. In the official Docker image it
registers the migration as a built-in post-setup task, because that image
creates its database configuration after Composer runs. The migration still
finishes before Apache starts. The plugin reads its custom environment
variables internally. No mounted PHP config, custom image, custom service
command, or manual migration step is required.

Mailbox administration on a Docker host is exposed through the bundled
`bin/roundcube-oidc-admin` launcher. It provides short commands for provisioning
users, adding mailboxes, rotating credentials, and enabling, disabling, or
removing assignments while keeping the OpenBao write token out of the web
container.

## Licence and source availability

The SizeStation distribution is licensed under AGPL-3.0-or-later. The retained
upstream plugin notices and complete GNU AGPL text are in
`plugins/ident_switch/LICENSE`. Anyone interacting with a modified deployed
version over a network must be offered the corresponding source for that exact
package release. Publish the immutable Git commit/tag and source archive; see
`SOURCE-AVAILABILITY.md`.
