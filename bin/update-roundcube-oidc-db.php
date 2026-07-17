<?php

declare(strict_types=1);

/** Initialize or update the combined plugin schema using Roundcube's database API. */
function sizestation_update_roundcube_oidc_db(string $roundcubeRoot, string $schemaDirectory): void
{
    $roundcubeRoot = rtrim($roundcubeRoot, '/\\') . '/';
    if (!defined('INSTALL_PATH')) {
        define('INSTALL_PATH', $roundcubeRoot);
    }
    if (rtrim((string) INSTALL_PATH, '/\\') . '/' !== $roundcubeRoot) {
        throw new RuntimeException('Roundcube installation path does not match the active runtime');
    }

    require_once INSTALL_PATH . 'program/include/clisetup.php';

    $package = 'roundcube_oidc_suite';
    if (rcmail_utils::db_version($package) === null) {
        rcmail_utils::db_init($schemaDirectory);
    }

    rcmail_utils::db_update($schemaDirectory, $package, null, ['errors' => true]);
}
