# Virtual City SMK Silber v5 — satu container: Apache + PHP 8.3 (web) dan Node.js (WebSocket :8090).
FROM php:8.3-apache-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=Asia/Jakarta

# Node.js (WebSocket server), sqlite3 CLI (debug), ekstensi zip, mod_evasive (anti-flood).
RUN apt-get update \
 && apt-get install -y --no-install-recommends nodejs npm sqlite3 libzip-dev tzdata ca-certificates libapache2-mod-evasive \
 && docker-php-ext-install -j"$(nproc)" zip opcache \
 && a2enmod rewrite headers proxy proxy_http proxy_wstunnel reqtimeout evasive \
 && a2dismod -f autoindex status \
 && apt-get purge -y --auto-remove libzip-dev \
 && apt-get install -y --no-install-recommends libzip4 \
 && rm -rf /var/lib/apt/lists/*

# Modul npm untuk server WebSocket
WORKDIR /opt/ws
RUN npm init -y >/dev/null && npm install --omit=dev ws@8.18.0 && npm cache clean --force
ENV NODE_PATH=/opt/ws/node_modules

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-virtual-city.ini
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache-security.conf /etc/apache2/conf-available/zz-security.conf
RUN a2enconf zz-security

WORKDIR /var/www
COPY public/ /var/www/public/
COPY private/ /var/www/private/
COPY docker/entrypoint.sh /usr/local/bin/vc-entrypoint
COPY docker/init_admin.php /var/www/private/bin/docker_init_admin.php

RUN chmod 755 /usr/local/bin/vc-entrypoint \
 && rm -rf /var/www/html \
 && mkdir -p private/data private/logs private/uploads/media-tmp private/backups public/assets/media public/assets/avatars \
 && chown -R root:www-data private public \
 && find private public -type d -exec chmod 750 {} + \
 && find private public -type f -exec chmod 640 {} + \
 && chmod 2770 private/data private/logs private/uploads private/uploads/media-tmp private/backups public/assets/media public/assets/avatars \
 && chmod 770 private/config

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
  CMD php -r '$c = stream_context_create(["http" => ["user_agent" => "vc-healthcheck"]]); exit(@file_get_contents("http://127.0.0.1/login", false, $c) === false ? 1 : 0);'

ENTRYPOINT ["vc-entrypoint"]
CMD ["apache2-foreground"]
