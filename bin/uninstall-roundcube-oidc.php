<?php

declare(strict_types=1);

$taskDirectory = getenv('ROUNDCUBE_POST_SETUP_TASK_DIRECTORY') ?: '/entrypoint-tasks/post-setup';
$taskPath = $taskDirectory . '/50-roundcube-oidc-db';
if ((file_exists($taskPath) || is_link($taskPath)) && !unlink($taskPath)) {
    throw new RuntimeException('Unable to remove the Roundcube OIDC post-setup task');
}
