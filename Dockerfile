# syntax=docker/dockerfile:1.7
#
# Image TechIndex – cibles :
#   dev        : PHP-FPM + Composer, code monté depuis le poste (compose.yaml)
#   production : PHP-FPM, dépendances sans outils de développement, ressources
#                compilées, code en lecture seule pour l'utilisateur www-data
#   web        : Nginx avec les fichiers publics compilés (compose.prod.yaml)
#   plesk-release : archive de déploiement pour un hébergement Plesk, sans
#                Docker sur le serveur (docs/deploiement-plesk.md)
#
# Versions figées (mettre à jour volontairement, voir docs/mise-a-jour.md).
ARG PHP_IMAGE=php:8.5.11-fpm-trixie
ARG NODE_IMAGE=node:24.21.0-trixie-slim
ARG COMPOSER_IMAGE=composer:2.10.3
ARG NGINX_IMAGE=nginx:1.30.5-alpine
# Archive Plesk uniquement : compilation d'un age statique.
ARG GO_IMAGE=golang:1.25.3-trixie

FROM ${COMPOSER_IMAGE} AS composer

# ---------------------------------------------------------------------------
# Base PHP commune
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS base

# Extensions : pdo_mysql (MariaDB), intl, zip (imports Excel), pcntl (worker).
# Outils : client MariaDB (sauvegardes), age (chiffrement des sauvegardes),
# cgi-fcgi (contrôle de santé de PHP-FPM).
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends mariadb-client age libfcgi-bin procps unzip ca-certificates; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get install -y --no-install-recommends libicu-dev libzip-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip pcntl; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark > /dev/null; \
    # Bibliothèques système utilisées par les extensions compilées : conservées.
    # (dpkg-query affiche aussi des lignes « diversion by ... » : seules les
    # lignes « paquet: chemin » sont retenues, comme dans les images PHP officielles.)
    find /usr/local -type f -executable -exec ldd '{}' ';' 2>/dev/null \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
        | sort -u \
        | xargs -r dpkg-query --search \
        | awk 'sub(":$", "", $1) { print $1 }' \
        | sort -u \
        | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    php -m | grep -E '^(pdo_mysql|intl|zip|pcntl|sodium|fileinfo)$'

COPY docker/php/conf.d/techindex.ini /usr/local/etc/php/conf.d/zz-techindex.ini
COPY docker/php/fpm/zz-techindex.conf /usr/local/etc/php-fpm.d/zz-techindex.conf
COPY --chmod=0755 docker/php/bin/ /usr/local/bin/

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# Développement (le code est monté par compose.yaml)
# ---------------------------------------------------------------------------
FROM base AS dev

COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_ALLOW_SUPERUSER=1
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Dépendances PHP de production
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# ---------------------------------------------------------------------------
# Compilation des ressources (Vue, TypeScript, Tailwind)
# ---------------------------------------------------------------------------
FROM ${NODE_IMAGE} AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.ts tsconfig.json ./
COPY resources ./resources
RUN npm run typecheck && npm run build

# ---------------------------------------------------------------------------
# Production : application PHP-FPM (également worker et planificateur)
# ---------------------------------------------------------------------------
FROM base AS production

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/conf.d/opcache-production.ini /usr/local/etc/php/conf.d/zz-opcache.ini

COPY --from=vendor /var/www/html /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build

# Le code appartient à root et n'est pas modifiable par www-data ; seuls
# storage/ et bootstrap/cache/ sont accessibles en écriture.
RUN set -eux; \
    rm -rf docker tests node_modules .env.example .env.production.example .env.plesk.example techindex.ps1 techindex.cmd scripts; \
    mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache /backups; \
    php artisan package:discover --ansi; \
    chown -R root:root /var/www/html; \
    chmod -R a-w,u+w /var/www/html; \
    chown -R www-data:www-data storage bootstrap/cache /backups; \
    chmod -R u=rwX,g=rX,o= storage bootstrap/cache /backups

USER www-data
ENTRYPOINT ["techindex-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Serveur web Nginx (fichiers statiques + relais vers PHP-FPM)
# ---------------------------------------------------------------------------
FROM ${NGINX_IMAGE} AS web

COPY docker/nginx/snippets/ /etc/nginx/snippets/
COPY docker/nginx/templates-available/ /etc/nginx/templates-available/
COPY --chmod=0755 docker/nginx/entrypoint.d/ /docker-entrypoint.d/
COPY public/ /var/www/html/public/
COPY --from=assets /app/public/build /var/www/html/public/build
RUN rm -f /var/www/html/public/index.php /var/www/html/public/.htaccess; mkdir -p /var/www/certbot

# ---------------------------------------------------------------------------
# Archive de déploiement Plesk (hébergement sans Docker) :
#   docker build --target plesk-release --build-arg TECHINDEX_VERSION=<version> \
#       --output type=local,dest=dist/plesk .
# Liste blanche : code, dépendances de production, ressources compilées et
# script d'exploitation ; ni tests, ni outils de développement, ni .env.
# ---------------------------------------------------------------------------

# Chiffrement des sauvegardes : age compilé sans dépendance à la glibc, pour
# fonctionner sur toutes les distributions prises en charge par Plesk. Les
# sources sont vérifiées par la base de sommes de contrôle Go (sum.golang.org).
FROM ${GO_IMAGE} AS age-static
ARG AGE_VERSION=v1.2.1
ARG AGE_ARCH=amd64
RUN set -eux; \
    mkdir /src; \
    cd /src; \
    go mod init techindex/age; \
    go get "filippo.io/age/cmd/age@${AGE_VERSION}" "filippo.io/age/cmd/age-keygen@${AGE_VERSION}"; \
    for tool in age age-keygen; do \
        CGO_ENABLED=0 GOOS=linux GOARCH="${AGE_ARCH}" go build -trimpath -ldflags='-s -w' -o "/age/${tool}" "filippo.io/age/cmd/${tool}"; \
    done

FROM vendor AS plesk-tree

ARG TECHINDEX_VERSION
COPY --from=assets /app/public/build /var/www/html/public/build
COPY --from=age-static /age/ /release/bin/

RUN set -eux; \
    test -n "${TECHINDEX_VERSION}"; \
    php artisan package:discover --ansi; \
    mkdir -p /release/resources /release/scripts /out; \
    cp -a app bootstrap config database lang public routes vendor artisan composer.json composer.lock .env.plesk.example /release/; \
    cp -a resources/views /release/resources/; \
    cp scripts/techindex-plesk.sh /release/scripts/; \
    # Canal des versions intégré au script (première installation depuis GitHub).
    channel="$(php -r '$c = json_decode((string) file_get_contents("config/release-channel.json"), true) ?: []; $r = (string) ($c["repository"] ?? ""); $k = (string) ($c["public_key"] ?? ""); if (preg_match("#^([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)?$#", $r) !== 1 || preg_match("#^[A-Za-z0-9+/=]*$#", $k) !== 1) { exit(1); } echo $r, " ", $k;')"; \
    sed -i -e "s|@RELEASE_REPOSITORY@|${channel%% *}|" -e "s|@RELEASE_PUBLIC_KEY@|${channel#* }|" /release/scripts/techindex-plesk.sh; \
    printf '%s\n' "${TECHINDEX_VERSION}" > /release/VERSION; \
    sed -i 's/\r$//' /release/scripts/techindex-plesk.sh /release/.env.plesk.example; \
    find /release -type d -exec chmod 755 {} +; \
    find /release -type f -exec chmod 644 {} +; \
    chmod 755 /release/artisan /release/bin/age /release/bin/age-keygen /release/scripts/techindex-plesk.sh; \
    name="techindex-plesk-${TECHINDEX_VERSION}"; \
    tar -czf "/out/${name}.tar.gz" --sort=name --owner=0 --group=0 --numeric-owner -C /release .; \
    cd /out; \
    sha256sum "${name}.tar.gz" > "${name}.tar.gz.sha256"; \
    cp /release/scripts/techindex-plesk.sh /out/techindex-plesk.sh

FROM scratch AS plesk-release
COPY --from=plesk-tree /out/ /
