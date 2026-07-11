# syntax=docker/dockerfile:1
#
# Production image for the Checkoutly licensing server / API gateway.
# FrankenPHP = Caddy + PHP in one process: automatic HTTPS (Let's Encrypt),
# no separate web server. The whole Symfony app (gateway + portal + Stripe +
# license signing) runs from here.

FROM dunglas/frankenphp:1-php8.4-bookworm

WORKDIR /app

# PHP extensions the app needs (Doctrine/MariaDB, intl for form/validator,
# zip for composer, opcache for perf). install-php-extensions ships in the image.
RUN install-php-extensions \
    intl \
    pdo_mysql \
    zip \
    opcache

# Production php.ini + our overrides (opcache, realpath cache).
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY frankenphp/conf.d/app.prod.ini "$PHP_INI_DIR/conf.d/zz-app.ini"

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

ENV APP_ENV=prod
ENV APP_DEBUG=0

# Install dependencies first (cached unless composer.* changes).
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist --optimize-autoloader

# App code.
COPY . .

RUN set -eux; \
    composer dump-autoload --no-dev --optimize --classmap-authoritative; \
    mkdir -p var/cache var/log; \
    php bin/console cache:clear --no-debug; \
    php bin/console assets:install public --no-debug; \
    chown -R www-data:www-data var public

# Custom Caddyfile (apex + www→apex redirect, compression).
COPY frankenphp/Caddyfile /etc/caddy/Caddyfile

# Our entrypoint warms cache + fixes perms, then hands off to FrankenPHP.
COPY frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
