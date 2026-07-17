<?php

/**
 * Single installable Roundcube plugin entrypoint for the SizeStation suite.
 *
 * Copyright (C) 2026 SizeStation
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$roundcubeRootAutoloader = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (is_file($roundcubeRootAutoloader)) {
    require_once $roundcubeRootAutoloader;
}

require_once __DIR__ . '/plugins/ident_switch/ident_switch.php';
require_once __DIR__ . '/plugins/sizestation_oidc/sizestation_oidc.php';
