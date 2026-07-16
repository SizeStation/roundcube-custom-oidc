#!/bin/sh
set -eu

roundcube_root="${ROUNDCUBE_PATH:-/var/www/html}"
package_root="${ROUNDCUBE_OIDC_SUITE_PATH:-${roundcube_root}/vendor/sizestation/roundcube-oidc-suite}"

install_plugin() {
    name="$1"
    source_dir="${package_root}/plugins/${name}"
    target_dir="${roundcube_root}/plugins/${name}"
    temporary_dir="${target_dir}.suite-new.$$"

    test -f "${source_dir}/${name}.php"
    rm -rf "${temporary_dir}"
    mkdir -p "${temporary_dir}"
    cp -a "${source_dir}/." "${temporary_dir}/"
    rm -rf "${target_dir}"
    mv "${temporary_dir}" "${target_dir}"
}

install_plugin ident_switch
install_plugin sizestation_oidc

test -f "${package_root}/bin/sizestation-oidc"
cp "${package_root}/bin/sizestation-oidc" "${roundcube_root}/bin/sizestation-oidc"
chmod 0755 "${roundcube_root}/bin/sizestation-oidc"

"${roundcube_root}/bin/initdb.sh" --dir="${roundcube_root}/plugins/ident_switch/SQL"
"${roundcube_root}/bin/initdb.sh" --dir="${roundcube_root}/plugins/sizestation_oidc/SQL"
