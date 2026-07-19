<?php

// TLS terminates at Traefik, so Roundcube must treat its public origin as
// HTTPS even though the service-to-proxy connection is plain HTTP.
$config['use_https'] = true;
$config['session_domain'] = '';
$config['session_path'] = '/';
$config['session_samesite'] = 'Lax';
$config['session_name'] = '__Host-roundcube_sessid';
$config['session_auth_name'] = '__Host-roundcube_sessauth';
