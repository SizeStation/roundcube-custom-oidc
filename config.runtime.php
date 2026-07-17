<?php

/**
 * Versioned runtime configuration for the SizeStation Roundcube plugin.
 *
 * Non-secret values may be supplied directly or through a Docker-style
 * NAME_FILE variable. Secrets remain file references and are never copied into
 * the process environment.
 */
$sizeStationReadConfigFile = static function (string $path): string {
    $value = @file_get_contents($path);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("Required runtime configuration file is unavailable: {$path}");
    }

    return trim($value);
};
$sizeStationEnvOrFile = static function (string $name, string $default = '') use ($sizeStationReadConfigFile): string {
    $value = trim((string) getenv($name));
    $file = trim((string) getenv($name . '_FILE'));
    if ($value !== '' && $file !== '') {
        throw new RuntimeException("Set either {$name} or {$name}_FILE, not both");
    }
    if ($file !== '') {
        return $sizeStationReadConfigFile($file);
    }

    return $value !== '' ? $value : $default;
};
$sizeStationEnvPath = static function (string $name, string $default): string {
    $value = trim((string) getenv($name));

    return $value !== '' ? $value : $default;
};
$sizeStationEnvList = static function (string $name, array $default) use ($sizeStationEnvOrFile): array {
    $value = $sizeStationEnvOrFile($name);
    if ($value === '') {
        return $default;
    }
    $items = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);

    return is_array($items) ? array_values(array_unique($items)) : $default;
};

$config['sizestation_oidc.enabled'] = true;
$config['sizestation_oidc.issuer'] = $sizeStationEnvOrFile(
    'ROUNDCUBE_OIDC_ISSUER',
    'https://auth.sizestation.cloud/application/o/roundcube/',
);
$config['sizestation_oidc.client_id'] = $sizeStationEnvOrFile('ROUNDCUBE_OIDC_CLIENT_ID');
$config['sizestation_oidc.client_secret_file'] = $sizeStationEnvPath(
    'ROUNDCUBE_OIDC_CLIENT_SECRET_FILE',
    '/run/secrets/oidc-client-secret',
);
$config['sizestation_oidc.redirect_uri'] = $sizeStationEnvOrFile(
    'ROUNDCUBE_OIDC_REDIRECT_URI',
    'https://mail.sizestation.cloud/?_task=login&_action=plugin.sizestation_oidc.callback',
);
$config['sizestation_oidc.post_logout_redirect_uri'] = $sizeStationEnvOrFile(
    'ROUNDCUBE_OIDC_POST_LOGOUT_REDIRECT_URI',
    'https://mail.sizestation.cloud/',
);
$config['sizestation_oidc.scopes'] = $sizeStationEnvList(
    'ROUNDCUBE_OIDC_SCOPES',
    ['openid', 'profile', 'email'],
);
$config['sizestation_oidc.external_user_id_claim'] = $sizeStationEnvOrFile(
    'ROUNDCUBE_OIDC_EXTERNAL_USER_ID_CLAIM',
    'sub',
);
$config['sizestation_oidc.allowed_algorithms'] = ['RS256'];
$config['sizestation_oidc.allowed_groups'] = [];
$config['sizestation_oidc.groups_claim'] = 'groups';
$config['sizestation_oidc.hide_password_form'] = true;
$config['sizestation_oidc.source_url'] = 'https://github.com/SizeStation/roundcube-custom-oidc';
$config['sizestation_oidc.clock_tolerance_seconds'] = 60;
$config['sizestation_oidc.connect_timeout_seconds'] = 2;
$config['sizestation_oidc.request_timeout_seconds'] = 5;
$config['sizestation_oidc.imap_host'] = $sizeStationEnvOrFile(
    'ROUNDCUBE_OIDC_IMAP_HOST',
    'ssl://imap.purelymail.com:993',
);

$config['sizestation_oidc.openbao_address'] = $sizeStationEnvOrFile(
    'ROUNDCUBE_OPENBAO_ADDRESS',
    'https://bao.sizestation.cloud',
);
$config['sizestation_oidc.openbao_token_file'] = $sizeStationEnvPath(
    'ROUNDCUBE_OPENBAO_TOKEN_FILE',
    '/run/secrets/openbao-token',
);
$config['sizestation_oidc.openbao_kv_mount'] = $sizeStationEnvOrFile('ROUNDCUBE_OPENBAO_KV_MOUNT', 'kv');
$config['sizestation_oidc.openbao_base_path'] = $sizeStationEnvOrFile(
    'ROUNDCUBE_OPENBAO_BASE_PATH',
    'roundcube/mailboxes',
);
$config['sizestation_oidc.openbao_ca_file'] = $sizeStationEnvPath(
    'ROUNDCUBE_OPENBAO_CA_FILE',
    '/etc/ssl/certs/ca-certificates.crt',
);
$config['sizestation_oidc.openbao_connect_timeout_seconds'] = 2;
$config['sizestation_oidc.openbao_request_timeout_seconds'] = 5;
$config['sizestation_oidc.openbao_provisioning_token_file'] = $sizeStationEnvPath(
    'ROUNDCUBE_OPENBAO_PROVISIONING_TOKEN_FILE',
    '/run/admin-secrets/openbao-provisioning-token',
);
$config['sizestation_oidc.openbao_provisioning_role_id'] = $sizeStationEnvOrFile(
    'ROUNDCUBE_OPENBAO_PROVISIONING_ROLE_ID',
);
$config['sizestation_oidc.openbao_provisioning_secret_id'] = $sizeStationEnvOrFile(
    'ROUNDCUBE_OPENBAO_PROVISIONING_SECRET_ID',
);
$config['sizestation_oidc.openbao_provisioning_approle_mount'] = $sizeStationEnvOrFile(
    'ROUNDCUBE_OPENBAO_PROVISIONING_APPROLE_MOUNT',
    'approle',
);
$config['sizestation_oidc.validate_imap_on_provision'] = true;
$config['sizestation_oidc.validate_smtp_on_provision'] = true;
$config['sizestation_oidc.validation_imap_endpoint'] = 'ssl://imap.purelymail.com:993';
$config['sizestation_oidc.validation_smtp_endpoint'] = 'tcp://smtp.purelymail.com:587';
$config['sizestation_oidc.validation_timeout_seconds'] = 10;

$config['ident_switch.managed_only'] = true;
$config['ident_switch.check_mail'] = true;
$config['ident_switch.managed_imap_host'] = 'ssl://imap.purelymail.com';
$config['ident_switch.managed_imap_port'] = 993;
$config['ident_switch.managed_smtp_host'] = 'ssl://smtp.purelymail.com';
$config['ident_switch.managed_smtp_port'] = 465;
$config['ident_switch.managed_sieve_host'] = null;
$config['ident_switch.openbao_address'] = $config['sizestation_oidc.openbao_address'];
$config['ident_switch.openbao_token_file'] = $config['sizestation_oidc.openbao_token_file'];
$config['ident_switch.openbao_kv_mount'] = $config['sizestation_oidc.openbao_kv_mount'];
$config['ident_switch.openbao_base_path'] = $config['sizestation_oidc.openbao_base_path'];
$config['ident_switch.openbao_ca_file'] = $config['sizestation_oidc.openbao_ca_file'];
