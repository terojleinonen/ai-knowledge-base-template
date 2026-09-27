# syntax=docker/dockerfile:1

# Production image: FrankenPHP (Caddy + PHP) serving the Laravel API.
# The same image runs the queue worker: override the command with
#   php artisan queue:work --tries=3 --timeout=900
# or set RUN_WORKER_IN_WEB=true to run it inside the web container (free tiers).

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts

FROM dunglas/frankenphp:1-php8.3 AS app

RUN install-php-extensions pdo_pgsql pdo_mysql intl zip pcntl opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf "upload_max_filesize=25M\npost_max_size=26M\nmemory_limit=512M\nopcache.validate_timestamps=0\n" > "$PHP_INI_DIR/conf.d/app.ini"

WORKDIR /app
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr SERVER_NAME=:8080

COPY --from=vendor /app /app

RUN php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s CMD php -r "exit(@file_get_contents('http://localhost:'.(getenv('PORT') ?: 8080).'/up') === false ? 1 : 0);"

# Config, routes and events are cached at start (docker/start.sh) so runtime environment variables are respected.
CMD ["sh", "docker/start.sh"]
