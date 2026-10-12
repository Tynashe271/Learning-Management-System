#!/bin/sh
# Starts php-fpm (listening on 127.0.0.1:9000) and nginx (listening on 8080, Railway's target port) in one container,
# since Railway runs one process group per service rather than compose's separate app/nginx containers.
set -e

# Migrations run as the database owner (DB_MIGRATE_*), since the app's own role can read/write rows but
# cannot alter tables. db:seed is safe to re-run every boot: besides permissions/roles, it only resets the
# three fixed admin/lecturer/student accounts (see DatabaseSeeder) back to their documented credentials.
php artisan migrate --database=pgsql_migrate --force
php artisan db:seed --force

php-fpm -D
nginx -g 'daemon off;' &
NGINX_PID=$!

trap 'kill -TERM $NGINX_PID 2>/dev/null; pkill -TERM php-fpm 2>/dev/null' TERM INT

wait $NGINX_PID
