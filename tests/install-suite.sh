#!/bin/sh
set -eu

repository_root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
fixture_root="$(mktemp -d)"
trap 'rm -rf "$fixture_root"' EXIT

mkdir -p "${fixture_root}/bin" "${fixture_root}/plugins" "${fixture_root}/vendor/sizestation"
ln -s "${repository_root}" "${fixture_root}/vendor/sizestation/roundcube-oidc-suite"
cat > "${fixture_root}/bin/initdb.sh" <<'EOF'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> "${ROUNDCUBE_PATH}/migration-calls"
EOF
chmod 0755 "${fixture_root}/bin/initdb.sh"

ROUNDCUBE_PATH="${fixture_root}" sh "${repository_root}/deployment/install-suite.sh"

test -f "${fixture_root}/plugins/ident_switch/ident_switch.php"
test -f "${fixture_root}/plugins/sizestation_oidc/sizestation_oidc.php"
test -x "${fixture_root}/bin/sizestation-oidc"
test "$(wc -l < "${fixture_root}/migration-calls")" -eq 2
grep -F -- '--dir='"${fixture_root}"'/plugins/ident_switch/SQL' "${fixture_root}/migration-calls"
grep -F -- '--dir='"${fixture_root}"'/plugins/sizestation_oidc/SQL' "${fixture_root}/migration-calls"
