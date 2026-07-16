FROM composer:2.8 AS dependencies

WORKDIR /build
COPY composer.json composer.lock ./
COPY packages/credentials/composer.json packages/credentials/composer.json
COPY packages/credentials/src packages/credentials/src
COPY plugins/sizestation_oidc/src plugins/sizestation_oidc/src
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader

FROM roundcube/roundcubemail:1.7.x-apache@sha256:76503fb00caf1cb0ee7731723d5bf31b492383b689d532fa943c70e885913687

COPY --from=dependencies /build/vendor /opt/sizestation/vendor
COPY plugins/ident_switch /usr/src/roundcubemail/plugins/ident_switch
COPY plugins/sizestation_oidc /usr/src/roundcubemail/plugins/sizestation_oidc
COPY bin/sizestation-oidc /usr/src/roundcubemail/bin/sizestation-oidc

RUN test -f /opt/sizestation/vendor/autoload.php \
    && test -f /usr/src/roundcubemail/plugins/ident_switch/ident_switch.php \
    && test -f /usr/src/roundcubemail/plugins/sizestation_oidc/sizestation_oidc.php \
    && chmod 0755 /usr/src/roundcubemail/bin/sizestation-oidc \
    && php -l /usr/src/roundcubemail/bin/sizestation-oidc \
    && php -r 'require "/usr/src/roundcubemail/program/include/iniset.php"; require "/usr/src/roundcubemail/plugins/ident_switch/ident_switch.php"; exit(class_exists("IdentSwitchCredentialService") ? 0 : 1);'
