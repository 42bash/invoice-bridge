#!/bin/sh
set -e

cd /var/www/html

# storage/ is a host volume: recreate the tree on first start.
mkdir -p storage/app/archive storage/database storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
[ -f storage/database/einvoicing.sqlite ] || touch storage/database/einvoicing.sqlite
chown -R www-data:www-data storage

# The web process and the scheduler start together and share one SQLite file.
# A lock keeps the two migrations from colliding on the first boot.
if [ "${EINVOICING_RUN_MIGRATIONS:-true}" = "true" ]; then
    mkdir -p storage/framework
    flock storage/framework/migrate.lock \
        su -s /bin/sh www-data -c "php artisan migrate --force --no-interaction" \
        || echo "migrations failed" >&2
fi

su -s /bin/sh www-data -c "php artisan config:cache && php artisan view:cache" >/dev/null || true

exec "$@"
