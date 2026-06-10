#!/usr/bin/env bash
set -euo pipefail

# CONTAINER_ROLE controls one-time bootstrap. Only the `web` role runs
# key:generate + migrate so the queue/scheduler containers don't race on
# the same migrations. Others just wait for the DB and start their process.
ROLE="${CONTAINER_ROLE:-web}"

echo "[entrypoint] starting role=${ROLE}"

# --- Wait for the PostgreSQL panel DB (infra `postgres` service) --------------
# DB_HOST/DB_PORT come from the environment (compose -> infra service name).
DB_HOST="${DB_HOST:-postgres}"
DB_PORT="${DB_PORT:-5432}"

echo "[entrypoint] waiting for postgres at ${DB_HOST}:${DB_PORT} ..."
for i in $(seq 1 30); do
    if pg_isready -h "${DB_HOST}" -p "${DB_PORT}" >/dev/null 2>&1; then
        echo "[entrypoint] postgres is ready"
        break
    fi
    sleep 2
    if [ "$i" -eq 30 ]; then
        echo "[entrypoint] WARNING: postgres still not ready after 60s, continuing"
    fi
done

# --- App key (only if missing) ------------------------------------------------
if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64' .env 2>/dev/null; then
    echo "[entrypoint] generating APP_KEY"
    php artisan key:generate --force || true
fi

# --- One-time bootstrap, web role only ---------------------------------------
if [ "${ROLE}" = "web" ]; then
    echo "[entrypoint] running migrations + seeders"
    php artisan migrate --force
    php artisan db:seed --class=AdminUserSeeder --force || true

    echo "[entrypoint] caching config/routes/views"
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true

    # Symlink for public storage (download routes use presigned S3, but keep parity)
    php artisan storage:link || true
fi

echo "[entrypoint] exec: $*"
exec "$@"
