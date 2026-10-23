# Imagen de PRODUCCIÓN del API Hacienda (la usa docker-compose.prod.yml).
# El docker-compose.yml de desarrollo no la usa: sigue con php:8.4.27-cli
# y el código por bind mount.
#
# FrankenPHP en modo clásico (sin worker): servidor de producción en un
# solo contenedor, con la raíz web en public/. Base fijada por DIGEST: hoy
# es FrankenPHP 1.13.2 + PHP 8.4.27 (parche de PHP2Shell). Dependabot
# (ecosistema docker) actualiza el digest.
FROM dunglas/frankenphp:1-php8.4-trixie@sha256:0de5a32944f72c13b45d183e8e6c53546b8172cec21cc8599e24b12e0661506d

# Piso de PHP: el build falla si la base trae un PHP sin el último parche
# de seguridad (PHP2Shell, CVE-2026-109618/109619).
ARG PHP_MIN_VERSION=8.4.27
RUN php -r 'exit(version_compare(PHP_VERSION, getenv("PHP_MIN_VERSION"), ">=") ? 0 : 1);' \
    || { echo "PHP $(php -r 'echo PHP_VERSION;') < ${PHP_MIN_VERSION}: base sin el parche de seguridad" >&2; exit 1; }

# pdo_mysql: MariaDB · gd: QR del API legacy (packages/legacy/phpqrcode)
# · zip: composer descomprime los paquetes en el build.
# dom, openssl, curl, mbstring y fileinfo ya vienen en la base.
RUN install-php-extensions \
    pdo_mysql \
    gd \
    zip \
    opcache

COPY docker/php.prod.ini /usr/local/etc/php/conf.d/99-hacienda.ini

WORKDIR /app

# El Caddy embebido necesita un storage escribible aunque no gestione TLS.
ENV XDG_CONFIG_HOME=/tmp/config XDG_DATA_HOME=/tmp/data

# Dependencias primero (capa cacheable). packages/ entra antes porque
# composer.json resuelve crlibre/xades-xmlseclibs desde ese path.
COPY composer.json composer.lock ./
COPY packages ./packages
RUN --mount=type=bind,from=composer:2,source=/usr/bin/composer,target=/usr/bin/composer \
    composer install --no-dev --no-autoloader --no-scripts --prefer-dist --no-interaction

COPY . .
RUN --mount=type=bind,from=composer:2,source=/usr/bin/composer,target=/usr/bin/composer \
    composer dump-autoload --optimize --no-dev

# Usuario sin privilegios. Escribibles:
#  - storage/: certificados .p12 y XML subidos (storage/app/legacy-files),
#    logs y cachés; en el compose va en un volumen persistente.
#  - bootstrap/cache/: config/route cache que genera el entrypoint.
#  - packages/legacy/phpqrcode/: el QR legacy escribe ahí su caché y su
#    log de errores; si no puede, falla en silencio.
RUN mkdir -p /tmp/config /tmp/data packages/legacy/phpqrcode/cache \
    && chown -R www-data:www-data /tmp/config /tmp/data storage bootstrap/cache packages/legacy/phpqrcode
USER www-data

EXPOSE 8000

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["frankenphp", "php-server", "--listen", ":8000", "--root", "public/"]
