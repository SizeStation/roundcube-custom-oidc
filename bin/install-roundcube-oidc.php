<?php

declare(strict_types=1);

$packageDirectory = dirname(__DIR__);
$roundcubeRoot = defined('INSTALL_PATH')
    ? rtrim((string) INSTALL_PATH, '/\\')
    : realpath($packageDirectory . '/../..');
if ($roundcubeRoot === false || !is_dir($roundcubeRoot)) {
    throw new RuntimeException('Unable to locate the Roundcube installation');
}

$roundcubeConfig = $roundcubeRoot . '/config/config.inc.php';
if (is_file($roundcubeConfig) && filesize($roundcubeConfig) > 0) {
    require_once __DIR__ . '/update-roundcube-oidc-db.php';
    sizestation_update_roundcube_oidc_db($roundcubeRoot, $packageDirectory . '/SQL');

    return;
}

// The official image installs Composer plugins before creating its database
// config. Register with its post-setup lifecycle so migration runs after core
// initialization and before Apache starts.
$dockerEntrypoint = getenv('ROUNDCUBE_DOCKER_ENTRYPOINT') ?: '/docker-entrypoint.sh';
$taskDirectory = getenv('ROUNDCUBE_POST_SETUP_TASK_DIRECTORY') ?: '/entrypoint-tasks/post-setup';
if (is_executable($dockerEntrypoint)) {
    if (!is_dir($taskDirectory) && !mkdir($taskDirectory, 0755, true) && !is_dir($taskDirectory)) {
        throw new RuntimeException('Unable to create the Roundcube post-setup task directory');
    }

    $taskPath = $taskDirectory . '/50-roundcube-oidc-db';
    if ((file_exists($taskPath) || is_link($taskPath)) && !unlink($taskPath)) {
        throw new RuntimeException('Unable to replace the Roundcube OIDC post-setup task');
    }
    $task = "#!/usr/bin/env php\n<?php\n\ndeclare(strict_types=1);\n\n"
        . 'require_once ' . var_export($packageDirectory . '/bin/update-roundcube-oidc-db.php', true) . ";\n\n"
        . 'sizestation_update_roundcube_oidc_db('
        . var_export($roundcubeRoot, true) . ', '
        . var_export($packageDirectory . '/SQL', true) . ");\n";
    if (file_put_contents($taskPath, $task, LOCK_EX) === false || !chmod($taskPath, 0755)) {
        throw new RuntimeException('Unable to register the Roundcube OIDC post-setup task');
    }

    echo "Registered automatic Roundcube OIDC database initialization.\n";

    return;
}

fwrite(
    STDERR,
    "Roundcube config is not initialized; run {$packageDirectory}/bin/update-roundcube-oidc-db after configuration.\n",
);
