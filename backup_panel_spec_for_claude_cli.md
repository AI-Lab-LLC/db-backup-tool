# ТЗ для Claude Code: PostgreSQL Backup Panel (Laravel)

Это задание для агента Claude Code. Построй веб-панель управления бэкапами PostgreSQL по спецификации ниже. Следуй разделам по порядку. В конце есть критерии приёмки — проверь их все.

---

## 1. Контекст инфраструктуры

Два сервера в Hetzner, соединены приватной сетью:

- **Сервер сайта** — приватный IP `10.0.0.2`. Здесь PHP, Laravel Forge, существующее приложение. **Панель ставим сюда.**
- **Сервер БД** — приватный IP `10.0.0.3`. PostgreSQL **версии 17**, конфиги в `/etc/postgresql/17/main/`.
- **Хранилище** — Hetzner Object Storage (S3-совместимое), регион **hel1**, endpoint `https://hel1.your-objectstorage.com`. Требует path-style endpoint.

Существующие базы на сервере БД: `breeze`, `nextdo`, `my_stock` (+ создадим `backup_panel` для самой панели). У каждой свой пользователь-владелец.

Панель вызывает `pg_dump` / `pg_restore` / `psql` **по сети** с `-h 10.0.0.3`, т.к. PostgreSQL на другом сервере.

---

## 2. Что должна уметь панель (функциональные требования)

1. **Список баз** — авто-обнаружение всех баз на сервере PostgreSQL (через `psql ... SELECT datname FROM pg_database`), плюс возможность добавить базу в отслеживание вручную.
2. **Настройка на каждую базу**: интервал автобэкапа в минутах (60 = каждый час, 1440 = раз в сутки, 0 = только вручную), срок хранения в днях (retention), вкл/выкл.
3. **Ручной бэкап** — кнопка «Бэкап сейчас» для любой базы.
4. **Автобэкап по расписанию** — планировщик проверяет интервалы и запускает бэкапы.
5. **История бэкапов** — таблица: база, файл, размер, статус (pending/running/success/failed), тип (manual/scheduled), время, ошибка если была.
6. **Скачивание** — через временную подписанную ссылку S3 (presigned URL, ~15 мин).
7. **Restore кнопкой** — восстановление дампа в выбранную базу. ОБЯЗАТЕЛЬНО с подтверждением (checkbox + предупреждение), т.к. перезаписывает данные.
8. **Удаление** бэкапа (из S3 и из истории).
9. **Авто-очистка** старых бэкапов по retention при каждом успешном бэкапе.

---

## 3. Технический стек (используй именно это)

- **Laravel** (последняя стабильная версия). Причина: на сервере уже PHP + Forge + Laravel-приложение, не плодим Python.
- **Очереди**: Laravel Queue, драйвер `database`. Бэкап — долгая операция, выполнять в Job, не в веб-запросе.
- **Расписание**: Laravel Scheduler (`schedule:run` через cron каждую минуту), читает интервалы из БД.
- **S3**: пакет `league/flysystem-aws-s3-v3 ^3.0`, диск `s3` в `config/filesystems.php` с endpoint и `use_path_style_endpoint => true`.
- **Авторизация**: Laravel Breeze (blade) — панель только за `auth`.
- **Выполнение команд**: `Symfony\Component\Process\Process`, пароль PostgreSQL передавать через env `PGPASSWORD`, НЕ в аргументах командной строки.
- **UI**: простой Blade + Tailwind (CDN допустим для MVP).

---

## 4. Схема БД (миграции)

Таблица `backup_configs`:
- `id`
- `database_name` (string) — имя базы в PostgreSQL
- `label` (string, nullable) — название для UI
- `enabled` (boolean, default true)
- `interval_minutes` (unsigned int, default 60) — 0 = только вручную
- `retention_days` (unsigned int, default 7)
- `last_run_at` (timestamp, nullable)
- timestamps

Таблица `backups`:
- `id`
- `database_name` (string)
- `filename` (string)
- `s3_path` (string) — полный ключ в бакете
- `size_bytes` (unsigned big int, nullable)
- `status` (enum: pending, running, success, failed; default pending)
- `trigger` (enum: manual, scheduled; default manual)
- `error` (text, nullable)
- `started_at`, `finished_at` (timestamp, nullable)
- timestamps

---

## 5. Компоненты, которые нужно создать

- **Модели**: `BackupConfig` (с методом `dueForBackup(): bool` — проверка, подошёл ли интервал по `last_run_at + interval_minutes`), `Backup`.
- **Сервис** `App\Services\BackupService` с методами:
  - `listDatabases(): array` — список баз через psql.
  - `backup(string $database, string $trigger): Backup` — pg_dump в формате custom (`-Fc`) во временный файл `storage/app/tmp`, заливка в S3 по ключу `postgres-backups/{db}/{db}_{timestamp}.dump`, запись в историю, удаление временного файла в finally.
  - `downloadUrl(Backup): string` — `Storage::disk('s3')->temporaryUrl(...)`.
  - `restore(Backup, string $targetDatabase): void` — скачать из S3, `pg_restore --clean --if-exists --no-owner -d {target}`. Учесть, что pg_restore часто выдаёт warnings в stderr — считать провалом только при наличии `FATAL`.
  - `pruneOld(string $database, int $retentionDays): void` — удалить из S3 и истории успешные бэкапы старше retention.
- **Job** `App\Jobs\RunBackupJob` (ShouldQueue, timeout ~3700): вызывает `backup()`, после успеха обновляет `last_run_at` конфига и вызывает `pruneOld()`.
- **Команда** `App\Console\Commands\DispatchScheduledBackups` (signature `backups:dispatch`): проходит по enabled-конфигам, для тех, где `dueForBackup()` — dispatch `RunBackupJob` с trigger=scheduled. Зарегистрировать в `routes/console.php` через `Schedule::command('backups:dispatch')->everyMinute()`.
- **Контроллер** `BackupController`: index, saveConfig (updateOrCreate), runNow (dispatch job), download (redirect на presigned url), restore (валидация + confirm accepted), destroy.
- **Маршруты** `routes/web.php` под middleware `auth`: GET /backups, POST /backups/config, POST /backups/run, GET /backups/{backup}/download, POST /backups/{backup}/restore, DELETE /backups/{backup}.
- **Вьюха** `resources/views/backups/index.blade.php`: блок настроек баз (таблица с inline-формами интервал/retention/enabled + кнопки «Сохранить» и «Бэкап сейчас»), блок добавления авто-обнаруженных баз, блок истории с кнопками Скачать/Restore/Удалить. Форма restore скрытая, раскрывается по кнопке, с предупреждением и checkbox подтверждения.

---

## 6. Переменные окружения (.env)

Создай и задокументируй:

```dotenv
# БД самой панели
DB_CONNECTION=pgsql
DB_HOST=10.0.0.3
DB_PORT=5432
DB_DATABASE=backup_panel
DB_USERNAME=backup_admin
DB_PASSWORD=

# Подключение к PostgreSQL для дампа
PG_HOST=10.0.0.3
PG_PORT=5432
PG_USER=backup_admin
PG_PASSWORD=

# Hetzner Object Storage (S3)
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=hel1
AWS_BUCKET=
AWS_ENDPOINT=https://hel1.your-objectstorage.com
AWS_USE_PATH_STYLE_ENDPOINT=true

QUEUE_CONNECTION=database
```

Пароли с спецсимволами оборачивать в двойные кавычки; рекомендовать буквенно-цифровые (`openssl rand -hex 24`).

---

## 7. Шаги установки, которые нужно описать в README проекта

1. Установить клиент PostgreSQL **17** на сервере сайта (репозиторий PGDG, пакет `postgresql-client-17`) — версия клиента должна совпадать с сервером.
2. На сервере БД создать роль `backup_admin` с LOGIN и доступом ко всем нужным базам. Для restore поверх существующих объектов проще `SUPERUSER` (отметить как риск; если restore не нужен — понизить права).
3. В `pg_hba.conf` на сервере БД добавить: `host all backup_admin 10.0.0.2/32 scram-sha-256`, затем `systemctl reload postgresql`.
4. Создать базу панели: `CREATE DATABASE backup_panel OWNER backup_admin;`.
5. `composer install`, `php artisan migrate`, `npm run build`.
6. Настроить cron на сервере сайта: `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`.
7. Запустить воркер очереди под systemd: `php artisan queue:work --timeout=3700 --tries=2`. Дать пример unit-файла.

---

## 8. Безопасность (обязательные требования)

- Все маршруты панели — за middleware `auth`.
- Панель доступна только по приватной сети / VPN, порт наружу не публиковать.
- Секреты только в `.env`, добавить в `.gitignore`.
- `PGPASSWORD` передавать через окружение процесса, не в аргументах команды (видны в `ps`).
- Restore требует явного подтверждения и предупреждения о перезаписи данных.
- Имена баз и целевую базу restore валидировать.

---

## 8b. Авторизация и доступ (детально)

Панель управляет бэкапами и умеет удалять/перезаписывать данные — доступ должен быть строго ограничен.

### Способ авторизации
- Использовать **Laravel Breeze (blade)**: `php artisan breeze:install blade`, затем `npm install && npm run build` и `php artisan migrate`.
- Это даёт готовые логин, выход, сброс пароля, профиль и таблицу `users`.

### Регистрация — закрыть
- Это внутренний инструмент, публичная регистрация не нужна. **Отключить маршруты регистрации**: удалить/закомментировать `register` в `routes/auth.php` (или убрать ссылку и заблокировать GET/POST `/register`).
- Аккаунты создавать только вручную через сидер или tinker:
  ```bash
  php artisan tinker
  >>> \App\Models\User::create(['name'=>'admin','email'=>'admin@example.com','password'=>bcrypt('надёжный_пароль')]);
  ```
- Сделать сидер `AdminUserSeeder`, который создаёт первого администратора из значений `.env` (`ADMIN_EMAIL`, `ADMIN_PASSWORD`), чтобы не хардкодить. Запускать `php artisan db:seed --class=AdminUserSeeder`.

### Защита маршрутов
- ВСЕ маршруты панели — в группе с middleware `['auth']` (уже указано в разделе 5). Ни один маршрут бэкапов не должен быть доступен анониму.
- Корневой `/` редиректит на `/login`, после входа — на `/backups`.
- Добавить `verified` middleware необязательно (почта может не работать на приватном сервере) — но если включаешь, настрой почту.

### Дополнительный слой (рекомендация)
Поскольку панель опасна, заложить хотя бы один из вариантов (минимум — первый):
1. **Сетевой барьер** — панель слушает только приватный интерфейс / за VPN; в веб-сервере (nginx) ограничить доступ по IP (`allow 10.0.0.0/16; deny all;`). Описать пример nginx-конфига в README.
2. **Basic Auth на уровне nginx** поверх Laravel-auth как второй фактор (htpasswd).
3. **2FA** — опционально, через пакет (например Laravel Fortify two-factor) — отметить как возможное улучшение, не обязательно для MVP.

### Роли (опционально, отметить как расширение)
Для MVP достаточно одного типа пользователя (админ). Если нужно разделение — заложить поле `role` в `users` и middleware/Gate: `viewer` (только смотреть и скачивать) vs `admin` (бэкап, restore, удаление). Restore и destroy разрешать только `admin`. Описать как опциональное расширение, не реализовывать если не просили.

### Пример nginx-ограничения по IP (для README)

```nginx
location / {
    allow 10.0.0.0/16;   # только приватная сеть
    deny all;
    try_files $uri $uri/ /index.php?$query_string;
}
```

### .env для авторизации

```dotenv
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=
```

---

## 9. Критерии приёмки (проверь перед сдачей)

- [ ] `php artisan migrate` создаёт обе таблицы без ошибок.
- [ ] На странице `/backups` (после логина) виден список баз, авто-обнаруженные базы можно добавить.
- [ ] Можно задать интервал и retention на базу и сохранить.
- [ ] Кнопка «Бэкап сейчас» создаёт запись в истории и (при работающем воркере) заливает файл в S3, статус становится `success`.
- [ ] Скачивание возвращает рабочую временную ссылку на файл из S3.
- [ ] Restore с подтверждением восстанавливает базу; без подтверждения — отклоняется.
- [ ] Команда `php artisan backups:dispatch` ставит в очередь бэкапы для баз с подошедшим интервалом.
- [ ] Старые бэкапы (старше retention) удаляются из S3 и истории.
- [ ] Все маршруты недоступны без авторизации (редирект на `/login`).
- [ ] Публичная регистрация отключена; админ создаётся через сидер из `.env`.
- [ ] После логина пользователь попадает на `/backups`, выход работает.
- [ ] (Если настроено) nginx ограничивает доступ по приватной подсети.
- [ ] Секреты не закоммичены, `PGPASSWORD` не светится в аргументах процессов.

---

## 10. Подсказки по реализации (на что обратить внимание)

- pg_dump/pg_restore версии клиента и сервера должны совпадать (обе 17), иначе возможны ошибки формата.
- Для больших баз ставь `Process::setTimeout(3600)` и Job `timeout` выше этого.
- `temporaryUrl` для S3 работает только если у диска корректно настроен endpoint и ключи; для Hetzner нужен path-style.
- Хранилище через публичный endpoint по HTTPS (приватная сеть Hetzner с Object Storage не связана) — это нормально, трафик шифруется TLS.
- Если хочешь меньше прав у `backup_admin`: для dump достаточно прав на чтение, но для дампа ВСЕХ объектов (включая чужие схемы) проще суперюзер. Документируй выбранный вариант.
