#!/bin/sh
set -e

cd /var/www/html

# Persistent volume starts empty: recreate the directories Laravel expects.
mkdir -p \
    storage/app/public \
    storage/app/private \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs
mkdir -p /var/www/backup-tmp
chown -R www-data:www-data storage bootstrap/cache /var/www/backup-tmp

run() { su -s /bin/sh www-data -c "php artisan $*"; }

if [ "$RUN_MIGRATIONS" = "true" ]; then
    run migrate --force
fi

run config:cache
run route:cache
run view:cache
run event:cache

exec "$@"
