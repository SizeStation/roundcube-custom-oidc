<?php

declare(strict_types=1);

/**
 * Package-local PSR-4 loader.
 *
 * roundcube/plugin-installer installs this package outside Composer's vendor
 * directory. Some Composer versions consequently omit the package namespaces
 * from the root autoload map, so the plugin must also be able to load its own
 * classes from its final Roundcube installation path.
 */
if (!defined('SIZESTATION_ROUNDCUBE_AUTOLOADER_REGISTERED')) {
    define('SIZESTATION_ROUNDCUBE_AUTOLOADER_REGISTERED', true);

    spl_autoload_register(static function (string $class): void {
        $prefixes = [
            'SizeStation\\Roundcube\\Credentials\\' => __DIR__ . '/packages/credentials/src/',
            'SizeStation\\Roundcube\\Oidc\\' => __DIR__ . '/plugins/sizestation_oidc/src/',
        ];

        foreach ($prefixes as $prefix => $directory) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relativeClass = substr($class, strlen($prefix));
            $file = $directory . str_replace('\\', '/', $relativeClass) . '.php';
            if (is_file($file)) {
                require_once $file;
            }

            return;
        }
    });
}
