<?php

// Mounted as /var/roundcube/config/sizestation_oidc.php. It contains no secret.
$readSecret = static function (string $path): string {
    $value = @file_get_contents($path);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("Required runtime secret is unavailable: {$path}");
    }

    return trim($value);
};

$config['des_key'] = $readSecret('/run/app-secrets/roundcube-des-key');
$config['plugins'] = array_values(array_unique(array_merge(
    (array) ($config['plugins'] ?? []),
    ['archive', 'zipdownload', 'ident_switch', 'sizestation_oidc'],
)));
$config['skin'] = 'elastic2022';
$config['username_domain'] = 'sizestation.com';
$config['imap_host'] = 'ssl://imap.purelymail.com:993';
$config['smtp_host'] = 'ssl://smtp.purelymail.com:465';
$config['smtp_user'] = '%u';
$config['smtp_pass'] = '%p';
$config['use_https'] = true;
$config['session_samesite'] = 'Lax';
$config['session_path'] = '/';
$proxyWhitelist = trim((string) getenv('ROUNDCUBE_PROXY_WHITELIST'));
$config['proxy_whitelist'] = $proxyWhitelist === ''
    ? []
    : array_values(array_filter(array_map('trim', explode(',', $proxyWhitelist))));

$config['sizestation_oidc.enabled'] = true;
$config['sizestation_oidc.issuer'] = 'https://auth.sizestation.cloud/application/o/roundcube/';
$config['sizestation_oidc.client_id'] = getenv('ROUNDCUBE_OIDC_CLIENT_ID');
$config['sizestation_oidc.client_secret_file'] = '/run/app-secrets/oidc-client-secret';
$config['sizestation_oidc.redirect_uri'] =
    'https://mail.sizestation.cloud/?_task=login&_action=plugin.sizestation_oidc.callback';
$config['sizestation_oidc.post_logout_redirect_uri'] = 'https://mail.sizestation.cloud/';
$config['sizestation_oidc.scopes'] = ['openid', 'profile', 'email', 'sizestation_user_id'];
$config['sizestation_oidc.external_user_id_claim'] = 'sizestation_user_id';
$config['sizestation_oidc.allowed_algorithms'] = ['RS256'];
$config['sizestation_oidc.allowed_groups'] = []; // e.g. ['roundcube-users']
$config['sizestation_oidc.groups_claim'] = 'groups';
$config['sizestation_oidc.hide_password_form'] = true;
$config['sizestation_oidc.source_url'] =
    'https://github.com/SizeStation/roundcube-custom-oidc';
$config['sizestation_oidc.clock_tolerance_seconds'] = 60;
$config['sizestation_oidc.connect_timeout_seconds'] = 2;
$config['sizestation_oidc.request_timeout_seconds'] = 5;
$config['sizestation_oidc.imap_host'] = 'ssl://imap.purelymail.com:993';

$config['sizestation_oidc.openbao_address'] = 'https://bao.sizestation.cloud';
$config['sizestation_oidc.openbao_token_file'] = '/run/app-secrets/openbao-token';
$config['sizestation_oidc.openbao_kv_mount'] = 'kv';
$config['sizestation_oidc.openbao_base_path'] = 'roundcube/mailboxes';
$config['sizestation_oidc.openbao_ca_file'] = '/etc/ssl/certs/ca-certificates.crt';
$config['sizestation_oidc.openbao_connect_timeout_seconds'] = 2;
$config['sizestation_oidc.openbao_request_timeout_seconds'] = 5;
$config['sizestation_oidc.openbao_provisioning_token_file'] =
    '/run/admin-secrets/openbao-provisioning-token';
$config['sizestation_oidc.validate_imap_on_provision'] = true;
$config['sizestation_oidc.validate_smtp_on_provision'] = true;
$config['sizestation_oidc.validation_imap_endpoint'] = 'ssl://imap.purelymail.com:993';
$config['sizestation_oidc.validation_smtp_endpoint'] = 'ssl://smtp.purelymail.com:465';
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
