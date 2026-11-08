#!/bin/sh
# Pastas graváveis pelo www-data (volumes e pastas do host) e depois o comando
# do container: php-fpm (web) ou mapos-cron (cron, já como www-data).
set -e

for pasta in /app/application/logs /app/assets/uploads /app/assets/uploads/temp /app/assets/userImage /storage /backups /traefik /tmp/mapos-htmlpurifier; do
    mkdir -p "$pasta"
    chown www-data:www-data "$pasta"
done

if [ "$1" = "mapos-cron" ]; then
    exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"
fi

exec "$@"
