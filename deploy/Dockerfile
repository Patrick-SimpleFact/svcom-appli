# Image du back-office (BO, API /v1, site) pour la mise en ligne (L01).
# Construite depuis backoffice/ : docker compose build (voir deploy/README.md).
# Base : PHP 8.4 FPM + Nginx (serversideup/php), servi sur le port 8080, utilisateur www-data.
FROM serversideup/php:8.4-fpm-nginx

USER root
RUN install-php-extensions intl
USER www-data

# Dépendances d'abord, pour garder ce calque en cache tant que composer.lock ne change pas.
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY --chown=www-data:www-data . .

# Autoload optimisé ; les scripts publient les fichiers de Filament (public/css, public/js, public/fonts).
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && mkdir -p storage/app/public storage/app/collecte storage/app/geoip storage/app/private \
        storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
