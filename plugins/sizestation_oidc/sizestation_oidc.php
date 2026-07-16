<?php

/**
 * Authentik OIDC and OpenBao-managed mailbox assignments for Roundcube.
 *
 * Copyright (C) 2026 SizeStation
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
class sizestation_oidc extends rcube_plugin
{
    public $task = 'login|logout|mail|settings';

    public function init(): void
    {
        $this->load_config();
    }
}
