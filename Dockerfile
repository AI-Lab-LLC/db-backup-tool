# Backup Panel — application image
# Single image used by web, queue worker and scheduler services (different commands).
#
# IMPORTANT: postgresql-client major version MUST match the PostgreSQL server.
# infra runs `postgres:17` (verified 17.9), so we install postgresql-client-17
# from the official PGDG apt repo (Debian bookworm slim base has only PG 15).
FROM php:8.3-cli-bookworm

# --- System deps + PostgreSQL 17 client (pg_dump / pg_restore / psql) ----------
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg lsb-release \
        git unzip libzip-dev libpng-dev libonig-dev libpq-dev; \
    # PGDG repo for postgresql-client-17 matching infra's postgres:17
    install -d /usr/share/postgresql-common/pgdg; \
    curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
        -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc; \
    echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] http://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends postgresql-client-17; \
    rm -rf /var/lib/apt/lists/*

# --- PHP extensions required by Laravel + pgsql -------------------------------
RUN set -eux; \
    docker-php-ext-configure gd; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql pgsql mbstring zip bcmath gd exif pcntl

# --- Composer (from official image) ------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- Node (for building front-end assets at image build time) -----------------
RUN set -eux; \
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -; \
    apt-get install -y --no-install-recommends nodejs; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Install PHP deps first (better layer caching)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --no-autoloader

# Install JS deps
COPY package.json package-lock.json ./
RUN npm ci

# Copy the rest of the application
COPY . .

# Finish composer autoload + build assets
RUN set -eux; \
    composer dump-autoload --optimize --no-dev; \
    npm run build; \
    rm -rf node_modules

# Permissions for Laravel writable dirs
RUN set -eux; \
    chmod -R ug+rwX storage bootstrap/cache

# Entrypoint prepares the app (key, migrate, caches) on container start.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
# Default command: web server. Overridden by queue/scheduler services in compose.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
