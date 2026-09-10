#!/bin/sh
set -e

echo "[Backend Entrypoint] Running database migrations..."
php bin/migrate.php

echo "[Backend Entrypoint] Seeding default system roles and accounts..."
php bin/seed_users.php

echo "[Backend Entrypoint] Starting PHP 8.4 Server on 0.0.0.0:8000..."
exec php -S 0.0.0.0:8000 -t public public/index.php
