# Corresponding source publication

The login page links to the public source repository through the
`sizestation_oidc.source_url` setting. For a production release, point this at
the immutable tag or commit corresponding exactly to the deployed image digest.

For every production image:

1. tag the tested Git commit (for example `roundcube-oidc-2026.07.16.1`);
2. publish that tag and a source archive, including the forked `ident_switch`,
   shared package, plugins, build files, and installation instructions;
3. label the container image with the source URL and Git revision;
4. expose a visible “Source code” link in the service or operator help page;
5. keep the source available for as long as the network service runs.

Do not publish OpenBao data, Docker secrets, runtime config containing secrets,
private CA keys, database backups, or user data. The upstream baseline and all
SizeStation fork changes are recorded in `docs/ident-switch-upstream-diff.md`.
