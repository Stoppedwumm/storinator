#!/bin/sh
set -e

echo "[Webapp Entrypoint] Initializing directories and permissions..."
mkdir -p /data /run/nginx /var/log/nginx
chmod -R 777 /data

echo "[Webapp Entrypoint] Running database migrations..."
if [ -f /var/www/backend/bin/migrate.php ]; then
    php /var/www/backend/bin/migrate.php || echo "[Webapp Entrypoint] Migration non-fatal warning."
fi

echo "[Webapp Entrypoint] Starting PHP-FPM..."
php-fpm -D

echo "[Webapp Entrypoint] Starting Nginx..."
exec nginx -g "daemon off;"
