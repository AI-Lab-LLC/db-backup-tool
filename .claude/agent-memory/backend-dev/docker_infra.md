---
name: docker-infra
description: Dockerization of the panel + the shared ~/workspace/infra services (postgres 17, MinIO) it connects to over external network workspace-net
metadata:
  type: project
---

Панель докеризована и подключается к уже работающей общей инфраструктуре в `~/workspace/infra` (compose project `workspace-infra`).

**Внешняя сеть:** `workspace-net` (driver bridge, `name: workspace-net`). Наш compose цепляется к ней как `external: true`.

**Сервисы infra (доступ по имени сервиса внутри сети):**
- `postgres` (контейнер `ws-postgres`, image `postgres:17`, проверено 17.9). Креды по умолчанию (в infra нет .env): user/pass `workspace`/`secret`, БД `workspace`. Init-скрипт `~/workspace/db/postgres/init/00-create-databases.sql` пустой (только примеры) и НЕ перезапустится — volume уже инициализирован. БД `backup_panel` создаётся one-shot сервисом `db-init`.
- `minio` (контейнер `ws-minio`, image `minio/minio`), API `:9000`, console `:9001`, креды `minioadmin`/`minioadmin`. Бакет `postgres-backups` создаётся one-shot сервисом `s3-init` (image `minio/mc`). MinIO требует path-style (`AWS_USE_PATH_STYLE_ENDPOINT=true`), endpoint `http://minio:9000`.
- Прочие: traefik, mysql, redis, mailpit, rabbitmq (нам не нужны).

**Why:** один общий стек сервисов на dev-машине; панель не должна дублировать БД/S3.

**How to apply:** при изменении docker-окружения панели — НЕ редактировать `~/workspace/infra` (только чтение); подключаться к `workspace-net`; для pg-клиента ставить postgresql-client-17 (major должен совпадать с infra postgres). В проде это 10.0.0.3 (PG) — те же PG_*/DB_* переменные.

**Файлы (в корне проекта):** `Dockerfile` (php:8.3-cli-bookworm + PGDG postgresql-client-17 + pdo_pgsql/pgsql/mbstring/zip/bcmath/gd/exif/pcntl + composer + node), `docker-compose.yml` (сервисы db-init, s3-init, web=`artisan serve`, queue=`queue:work --timeout=3700 --tries=2`, scheduler=`schedule:work`), `docker/entrypoint.sh` (ждёт pg, key:generate, web-роль делает migrate + AdminUserSeeder + config/route/view:cache), `.env.docker`, `.dockerignore`.

**Связанные инварианты:** scheduler работает потому что `routes/console.php` содержит `Schedule::command('backups:dispatch')->everyMinute()`. AdminUserSeeder читает `config('backup.admin.*')` <- env ADMIN_EMAIL/ADMIN_PASSWORD. См. [[job_command_dispatch]], [[backup_service]], [[project_bootstrap]].
