# PostgreSQL Backup Panel

Веб-панель управления бэкапами PostgreSQL на Laravel 13. Позволяет
просматривать список баз, настраивать автобэкап по расписанию, делать
бэкап вручную, скачивать дампы по временной ссылке, восстанавливать базы
и автоматически чистить старые бэкапы по сроку хранения (retention).

---

## 1. Назначение и архитектура

Панель устанавливается на **сервер сайта** и управляет бэкапами
PostgreSQL, который работает на **отдельном сервере БД**. Дампы хранятся в
S3-совместимом Hetzner Object Storage.

```
┌─────────────────────────┐         ┌──────────────────────────┐
│  Сервер сайта 10.0.0.2  │         │   Сервер БД 10.0.0.3     │
│                         │         │                          │
│  Laravel Backup Panel   │ ─pg_*─▶ │  PostgreSQL 17           │
│  cron → schedule:run    │  -h     │  :5432                   │
│  queue:work (RunBackupJob)        │  pg_hba.conf разрешает    │
│  postgresql-client-17   │         │  доступ с 10.0.0.2       │
└───────────┬─────────────┘         └──────────────────────────┘
            │
            │ HTTPS (path-style)
            ▼
┌──────────────────────────────────────────┐
│  Hetzner Object Storage (S3), регион hel1 │
│  endpoint https://hel1.your-objectstorage.com │
│  ключи: postgres-backups/{db}/{db}_{ts}.dump │
└──────────────────────────────────────────┘
```

**Поток автобэкапа:**

```
cron (* * * * *)
  → php artisan schedule:run
    → backups:dispatch  (DispatchScheduledBackups)
      → для каждого enabled-конфига с dueForBackup() === true:
        → dispatch RunBackupJob(database, trigger=scheduled)
          → BackupService::backup()  (pg_dump -Fc → storage/app/tmp → S3)
          → обновляет backup_configs.last_run_at
          → BackupService::pruneOld()  (удаляет дампы старше retention)
```

Ручной бэкап идёт тем же путём, минуя scheduler: контроллер сразу
делает `dispatch(RunBackupJob)` с `trigger=manual`.

**Декомпозиция слоёв (инвариант):** контроллеры и Job вызывают только
`App\Services\BackupService`; процессы (`pg_dump`/`pg_restore`/`psql`)
запускаются исключительно внутри сервиса. Поле `backups.last_run_at`
изменяется **только** в `RunBackupJob` после успешного бэкапа.

---

## 2. Требования

На **сервере сайта** (10.0.0.2):

- **PHP 8.3** (с расширениями, нужными Laravel: pdo_pgsql, mbstring, openssl, и т.д.).
- **Composer**.
- **Node.js + npm** (для сборки фронтенда через Vite).
- **postgresql-client-17** — пакет с `psql`, `pg_dump`, `pg_restore`.
  **Major-версия клиента ОБЯЗАНА совпадать с сервером — обе 17.** Иначе
  pg_dump/pg_restore могут падать на несовместимости формата дампа.
- Сетевой доступ к **10.0.0.3:5432** (PostgreSQL) и к S3-endpoint
  `https://hel1.your-objectstorage.com` (HTTPS, исходящий).

Установка клиента PostgreSQL 17 (репозиторий PGDG, Debian/Ubuntu):

```bash
sudo apt install -y curl ca-certificates
sudo install -d /usr/share/postgresql-common/pgdg
sudo curl -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
  https://www.postgresql.org/media/keys/ACCC4CF8.asc
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] \
  https://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" \
  | sudo tee /etc/apt/sources.list.d/pgdg.list
sudo apt update
sudo apt install -y postgresql-client-17

# проверка версии (должна быть 17.x)
pg_dump --version
psql --version
```

---

## 3. Установка панели

На сервере сайта, в каталоге проекта:

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate

# отредактируйте .env (см. раздел 5)

php artisan migrate
npm install && npm run build
```

Миграции создают таблицы `users` (Breeze), `backup_configs`, `backups`,
а также служебные таблицы драйверов `database` (jobs, cache, sessions).

---

## 4. Подготовка PostgreSQL (на сервере БД 10.0.0.3)

Панель подключается к PostgreSQL по сети под ролью `backup_admin`.
Нужно создать роль, выдать права и разрешить подключение в `pg_hba.conf`.

### 4.1. Роль для бэкапов

```sql
-- На сервере БД, под суперюзером (например postgres):
CREATE ROLE backup_admin WITH LOGIN PASSWORD 'надёжный_пароль' SUPERUSER;
```

**Про права:** чтобы дампить ВСЕ объекты во всех схемах любой базы и
выполнять `restore` поверх существующих объектов (`pg_restore --clean`),
проще всего выдать `SUPERUSER`. Это **повышенный риск** — роль получает
полный контроль над кластером.

Если restore не требуется и достаточно только дампа своих баз, понизьте
права: уберите `SUPERUSER` и выдайте подключение/чтение точечно,
например:

```sql
CREATE ROLE backup_admin WITH LOGIN PASSWORD 'надёжный_пароль';
GRANT CONNECT ON DATABASE backup_panel, breeze, nextdo, my_stock TO backup_admin;
-- + права на схемы/таблицы нужных баз, либо членство в ролях-владельцах
```

> Restore деструктивен и использует `pg_restore --clean --if-exists --no-owner`:
> для удаления и пересоздания чужих объектов почти наверняка нужны права
> владельца или суперюзера на целевой базе. Документируйте выбранный
> вариант под свои нужды.

### 4.2. База самой панели

```sql
CREATE DATABASE backup_panel OWNER backup_admin;
```

### 4.3. Доступ в pg_hba.conf

В `/etc/postgresql/17/main/pg_hba.conf` на сервере БД добавьте строку,
разрешающую подключение с сервера сайта:

```
# TYPE  DATABASE  USER          ADDRESS        METHOD
host    all       backup_admin  10.0.0.2/32    scram-sha-256
```

Затем перезагрузите конфиг:

```bash
sudo systemctl reload postgresql
```

Убедитесь, что `listen_addresses` в `postgresql.conf` включает приватный
интерфейс (например `listen_addresses = 'localhost,10.0.0.3'`).

---

## 5. Переменные окружения (.env)

Все ключи берутся из `.env.example`. Заполните пустые значения секретами
(только в `.env`, который в `.gitignore`).

| Ключ | Назначение |
|------|------------|
| `APP_KEY` | Ключ шифрования Laravel. Генерируется `php artisan key:generate`. |
| `APP_ENV` | Окружение. В проде поставьте `production`. |
| `APP_DEBUG` | Отладка. В проде — `false`. |
| `APP_URL` | Базовый URL панели. |
| **БД самой панели** | |
| `DB_CONNECTION` | `pgsql` — панель использует PostgreSQL. |
| `DB_HOST` | Хост БД панели — `10.0.0.3`. |
| `DB_PORT` | Порт — `5432`. |
| `DB_DATABASE` | Имя базы панели — `backup_panel`. |
| `DB_USERNAME` | Пользователь — `backup_admin`. |
| `DB_PASSWORD` | Пароль роли `backup_admin`. |
| **Подключение для pg_dump/pg_restore/psql** | |
| `PG_HOST` | Хост PostgreSQL для дампов — `10.0.0.3`. |
| `PG_PORT` | Порт — `5432`. |
| `PG_USER` | Пользователь для дампов — `backup_admin`. |
| `PG_PASSWORD` | Пароль; передаётся в процессы через `PGPASSWORD` (env, не в argv). |
| **Очередь** | |
| `QUEUE_CONNECTION` | `database` — бэкапы выполняются в очереди. |
| **Hetzner Object Storage (S3)** | |
| `AWS_ACCESS_KEY_ID` | Access key объектного хранилища. |
| `AWS_SECRET_ACCESS_KEY` | Secret key объектного хранилища. |
| `AWS_DEFAULT_REGION` | Регион — `hel1`. |
| `AWS_BUCKET` | Имя бакета для дампов. |
| `AWS_ENDPOINT` | Endpoint — `https://hel1.your-objectstorage.com`. |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` — Hetzner требует path-style. |
| **Сидер админа** | |
| `ADMIN_EMAIL` | Email первого администратора (для `AdminUserSeeder`). |
| `ADMIN_PASSWORD` | Пароль первого администратора (для `AdminUserSeeder`). |

> **Пароли** со спецсимволами оборачивайте в двойные кавычки. Рекомендуется
> буквенно-цифровой пароль, например `openssl rand -hex 24`.

Прочие ключи в `.env.example` (`SESSION_DRIVER=database`,
`CACHE_STORE=database`, `MAIL_*`, `LOG_*`) можно оставить по умолчанию для
MVP.

---

## 6. Аккаунты и авторизация

Авторизация — Laravel Breeze (blade). **Публичная регистрация отключена**
(это внутренний инструмент). Все маршруты панели за middleware `auth`;
корень `/` ведёт на `/login`, после входа — на `/backups`.

Первого администратора создаёт сидер `AdminUserSeeder` из значений
`.env` (`ADMIN_EMAIL`, `ADMIN_PASSWORD`):

```bash
php artisan db:seed --class=AdminUserSeeder
```

Альтернатива — создать пользователя вручную через tinker:

```bash
php artisan tinker
>>> \App\Models\User::create(['name'=>'admin','email'=>'admin@example.com','password'=>bcrypt('надёжный_пароль')]);
```

---

## 7. Фоновые процессы

Для автобэкапов нужны **cron (scheduler)** и **воркер очереди**.

### 7.1. Cron — планировщик

Добавьте в crontab пользователя приложения (на сервере сайта):

```cron
* * * * * cd /path/to/backup_panel && php artisan schedule:run >> /dev/null 2>&1
```

Каждую минуту Laravel запускает `backups:dispatch`, который ставит в
очередь бэкапы для баз с подошедшим интервалом.

### 7.2. Воркер очереди (systemd)

`RunBackupJob` имеет timeout ~3700 секунд, поэтому у воркера
`--timeout` должен быть **>= 3700**.

Пример unit-файла `/etc/systemd/system/backup-panel-worker.service`:

```ini
[Unit]
Description=Backup Panel queue worker
After=network.target

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/path/to/backup_panel
ExecStart=/usr/bin/php /path/to/backup_panel/artisan queue:work \
  --timeout=3700 --tries=2 --sleep=3
# держим воркер живым; перезапуск после деплоя — systemctl restart

[Install]
WantedBy=multi-user.target
```

Включение и запуск:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now backup-panel-worker
sudo systemctl status backup-panel-worker
```

После каждого деплоя перезапускайте воркер, чтобы он подхватил новый код:
`php artisan queue:restart` (мягко) или `sudo systemctl restart backup-panel-worker`.

---

## 8. nginx

Панель опасна (умеет удалять/перезаписывать данные) — **порт наружу не
публикуется**, доступ ограничивается приватной подсетью.

Пример server-блока:

```nginx
server {
    listen 80;
    server_name backup.internal;          # или приватный IP
    root /path/to/backup_panel/public;
    index index.php;

    # Сетевой барьер — только приватная сеть
    allow 10.0.0.0/16;   # при необходимости укажите конкретные IP
    deny all;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

`allow`/`deny` можно поместить и внутрь `location /` — для жёсткого
ограничения проще объявить их на уровне `server`, тогда весь сайт
закрыт для внешних адресов.

### Дополнительные слои (по желанию)

- **Basic Auth (nginx)** поверх Laravel-auth как второй фактор
  (`auth_basic` + `htpasswd`).
- **2FA** через пакет (например Laravel Fortify) — возможное улучшение,
  для MVP не обязательно.

---

## 9. Безопасность (обязательные требования)

- **`PGPASSWORD` только через окружение процесса**, никогда в аргументах
  CLI — иначе пароль виден в `ps`. Сервис передаёт пароль в env процесса
  `pg_dump`/`pg_restore`/`psql`.
- **Секреты только в `.env`** (в `.gitignore`); ничего не коммитим.
- **Все маршруты панели за `auth`**; публичная регистрация отключена.
- **Restore деструктивен** (`pg_restore --clean --if-exists --no-owner`):
  требует явного подтверждения (checkbox + предупреждение) перед
  выполнением; имена баз и целевая база валидируются. Провалом restore
  считается только наличие `FATAL` в stderr (warnings на stderr — норма).
- **Порт не публикуется** в интернет: панель только в приватной сети / за
  VPN, в nginx ограничена по IP.
- Трафик к S3 идёт по публичному endpoint, но шифруется TLS (приватная
  сеть Hetzner с Object Storage не связана — это нормально).

---

## 10. Проверка после установки

```bash
php artisan migrate:status                     # таблицы на месте
php artisan backups:dispatch                   # ставит due-бэкапы в очередь
sudo systemctl status backup-panel-worker      # воркер активен
crontab -l                                      # schedule:run в cron
```

Затем войдите на `/backups`, добавьте авто-обнаруженную базу, задайте
интервал/retention и нажмите «Бэкап сейчас» — при работающем воркере
статус записи в истории должен стать `success`, а файл появиться в S3.
