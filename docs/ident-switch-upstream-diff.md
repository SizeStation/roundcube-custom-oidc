# ident_switch SizeStation changes

Upstream baseline: Gecka-Apps `ident_switch` 5.0.4
(`8f45736b1bd74679721cd311e9a2ed306d2395f2`).

This ledger will be updated with every fork change so upgrades and upstreamable
patches remain reviewable.

## Distribution scaffold

- Added an empty `tests` directory so the distribution-level PHPUnit suite can
  discover fork tests as they are introduced. No upstream runtime code changed.
