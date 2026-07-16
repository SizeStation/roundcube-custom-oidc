FROM composer:2.8@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c AS dependencies

ADD --checksum=sha256:e3d93b095e9ed27d36bfd31013859e64c79170e56f2ab40767efd1ea66baf970 \
    https://github.com/seb1k/Elastic2022/archive/8f4a1e451f8f37980ad90f7fe83e96730b359fee.tar.gz \
    /tmp/elastic2022.tar.gz
RUN mkdir /tmp/elastic2022 \
    && tar -xzf /tmp/elastic2022.tar.gz --strip-components=1 -C /tmp/elastic2022 \
    && rm /tmp/elastic2022.tar.gz

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

ARG VCS_REF=unknown
LABEL org.opencontainers.image.source="https://github.com/SizeStation/roundcube-custom-oidc" \
      org.opencontainers.image.revision="${VCS_REF}" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later"

COPY --from=dependencies /build/vendor /opt/sizestation/vendor
COPY plugins/ident_switch /usr/src/roundcubemail/plugins/ident_switch
COPY plugins/sizestation_oidc /usr/src/roundcubemail/plugins/sizestation_oidc
COPY bin/sizestation-oidc /usr/src/roundcubemail/bin/sizestation-oidc
COPY --from=dependencies /tmp/elastic2022 /usr/src/roundcubemail/skins/elastic2022
COPY LICENSE.md SOURCE-AVAILABILITY.md THIRD-PARTY-NOTICES.md /usr/share/doc/roundcube-custom-oidc/
COPY plugins/ident_switch/LICENSE /usr/share/doc/roundcube-custom-oidc/AGPL-3.0.txt

RUN test -f /opt/sizestation/vendor/autoload.php \
    && test -f /usr/src/roundcubemail/plugins/ident_switch/ident_switch.php \
    && test -f /usr/src/roundcubemail/plugins/sizestation_oidc/sizestation_oidc.php \
    && test -f /usr/src/roundcubemail/skins/elastic2022/meta.json \
    && chmod 0755 /usr/src/roundcubemail/bin/sizestation-oidc \
    && php -l /usr/src/roundcubemail/bin/sizestation-oidc \
    && php -r 'require "/usr/src/roundcubemail/program/include/iniset.php"; require "/usr/src/roundcubemail/plugins/ident_switch/ident_switch.php"; exit(class_exists("IdentSwitchCredentialService") ? 0 : 1);'
