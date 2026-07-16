FROM composer:2.8 AS dependencies

WORKDIR /build
COPY composer.json composer.lock ./
COPY packages/credentials/composer.json packages/credentials/composer.json
COPY packages/credentials/src packages/credentials/src
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader

FROM roundcube/roundcubemail:1.7.x-apache@sha256:76503fb00caf1cb0ee7731723d5bf31b492383b689d532fa943c70e885913687

COPY --from=dependencies /build/vendor /var/www/html/vendor
COPY plugins/ident_switch /var/www/html/plugins/ident_switch
COPY plugins/sizestation_oidc /var/www/html/plugins/sizestation_oidc

RUN test -f /var/www/html/vendor/autoload.php \
    && test -f /var/www/html/plugins/ident_switch/ident_switch.php \
    && test -f /var/www/html/plugins/sizestation_oidc/sizestation_oidc.php
