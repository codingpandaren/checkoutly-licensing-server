#!/bin/sh
set -e

# Runs on container start. The prod cache is already warmed in the image (env
# values are resolved at runtime, so the mounted .env.local still applies). We
# only ensure var/ is writable for logs/runtime cache.
if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	mkdir -p var/cache var/log
	chown -R www-data:www-data var || true
fi

exec docker-php-entrypoint "$@"
