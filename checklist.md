# QA Checklist — PostgreSQL Backup Panel (задача Z11)

Дата: 2026-06-05. Окружение: PHP 8.3.6, sqlite (временная /tmp/qa.sqlite), pg-бинари недоступны (реальный dump/restore не выполнялся — проверены код и поведение).

## Hard Invariants (CLAUDE.md)

| # | Инвариант | Статус | Комментарий |
|---|-----------|--------|-------------|
| 1 | PGPASSWORD только в env процесса, не в argv | **PASS** | `BackupService::makeProcess()` (app/Services/BackupService.php:266-277) передаёт `['PGPASSWORD' => ...]` третьим аргументом `new Process`. `connectionArgs()` (:250) содержит только `-h/-p/-U`. grep по `app/` — пароль нигде не в argv. |
| 2 | Декаплинг: контроллер/команда/джоб не запускают Process напрямую | **PASS** | Единственный `new Process`/`pg_dump`/`pg_restore` — в BackupService. Совпадение в BackupController — только doc-комментарий (строка 19). Команда (DispatchScheduledBackups) и Job делают только dispatch/вызов сервиса. |
| 3 | last_run_at мутируется ТОЛЬКО в RunBackupJob | **PASS** | Единственная запись — RunBackupJob.php:56 (`$config->update(['last_run_at' => now()])`). В BackupService/контроллере отсутствует (подтверждено grep по `app/`). |
| 4 | backup() -Fc → storage/app/tmp, ключ postgres-backups/{db}/{db}_{ts}.dump, temp в finally | **PASS** | app/Services/BackupService.php:67-146: `-Fc` (:91), tmp `storage_path('app/tmp')` (:82), ключ через `s3Key()` (:282-287) = `postgres-backups/{db}/{db}_{Ymd_His}.dump`, `@unlink` в `finally` (:141-144). |
| 5 | pruneOld вызывается после успешного backup в RunBackupJob | **PASS** | RunBackupJob::handle (:43-59): при `status==='success'` → `last_run_at` + `pruneOld($db, retention_days)` (:57). |
| 6 | restore() --clean --if-exists --no-owner; провал ТОЛЬКО при FATAL | **PASS** | app/Services/BackupService.php:170-213: флаги (:192-194), провал только `hasFatal()` = `str_contains($stderr,'FATAL')` (:205, :292-295). Невалидное имя цели — отдельный RuntimeException (:172). |
| 7 | Все панельные роуты за auth; роутов register нет | **PASS** | `route:list` подтверждает: backups.* в группе `web`+`auth`; `register` отсутствует. routes/auth.php — register удалён (комментарий :15). |
| 8 | RunBackupJob ShouldQueue, timeout ~3700 | **PASS** | RunBackupJob implements ShouldQueue (:13), `public int $timeout = 3700;` (:23), `$tries = 2` (:28). |
| 9 | restore: confirm-checkbox (accepted) + предупреждение + submit disabled | **PASS** | Контроллер валидирует `'confirm' => ['accepted']` + `target_database regex` (BackupController.php:133-136). Вьюха: чекбокс `name="confirm" x-model="confirmed"` (:428-431), предупреждение «ПЕРЕЗАПИШЕТ И УНИЧТОЖИТ» (:378), submit `:disabled="!confirmed"` (:449). |

## Функционально (спека §9) + MVP-тесты

| # | Пункт | Статус | Комментарий |
|---|-------|--------|-------------|
| 10 | migrate создаёт обе таблицы | **PASS** | `migrate` на /tmp/qa.sqlite — 5 миграций OK; таблицы `backup_configs` (9 колонок, unique database_name) и `backups` (status/trigger default, индексы) присутствуют. |
| 11 | dueForBackup() граничные случаи | **PASS** | Написан `tests/Unit/DueForBackupTest.php` (6 кейсов: interval=0→false, disabled→false, last_run_at=null→true, прошёл→true, не прошёл→false, ровно на границе→true). Все зелёные. |
| 12 | AdminUserSeeder из ADMIN_EMAIL/ADMIN_PASSWORD | **PASS** | Inline-env seed создал пользователя `qa@example.com`; при пустых ADMIN_* — корректно пропускает с предупреждением. См. примечание L1 ниже (env() vs config:cache). |
| 13 | Гость на /backups → /login | **PASS** | `tests/Feature/BackupRoutesAuthTest.php` (index/run/restore → redirect /login) — зелёные. |
| 14 | backups:dispatch диспатчит due+enabled (Bus::fake) | **PASS** | Написан `tests/Feature/DispatchScheduledBackupsTest.php`: из 5 конфигов диспатчатся ровно 2 due+enabled с trigger=scheduled; disabled/manual-only/не-due пропущены. Зелёные. |
| 15 | npm run build; вьюха рендерится без blade-ошибок | **PASS** | `npm run build` exit 0 (vite, CSS+JS собраны). `php artisan view:cache` exit 0 — все blade компилируются. |
| 16 | php artisan test | **PASS (с оговоркой)** | 38 тестов, 37 PASS, 1 FAIL. Единственный FAIL — стоковый `ExampleTest::test_the_application_returns_a_successful_response` (ожидает 200 от `/`, получает 302). Это НЕ баг продукта: `/` корректно редиректит гостя на /login по спеке §8b. См. B1. |

## Дополнительно

| Пункт | Статус | Комментарий |
|-------|--------|-------------|
| x-cloak CSS | **PASS** | `[x-cloak]{display:none!important}` в resources/css/app.css:6 и в собранном public/build/assets/app-*.css. Restore-форма (`x-cloak` :364) не мелькнёт. |
| FILESYSTEM_DISK не мешает | **PASS** | Сервис и контроллер используют `config('backup.disk','s3')` явно — независимо от FILESYSTEM_DISK. |
| S3 path-style/endpoint | **PASS** | config/filesystems.php: `endpoint` hel1, `use_path_style_endpoint => true`. |
| Секреты не закоммичены | **PASS** | .gitignore: `.env`, `.env.*`, `!.env.example`; все PASSWORD/SECRET в .env.example пустые. |

## Найденные пункты

- [ ] **[LOW] Стоковый ExampleTest падает (ложноположительный)** (`tests/Feature/ExampleTest.php:13`) (B1)
  - Ожидание теста: `GET /` → 200. Фактически → 302 redirect на /login (для гостя), что соответствует спеке §8b.
  - Это устаревший дефолтный тест Breeze, а не дефект продукта. Поведение приложения корректно.
  - Рекомендация: обновить тест под редирект (`$this->get('/')->assertRedirect(route('login'))`) или удалить. Без фикса `php artisan test` всегда будет показывать 1 FAIL.
  - Repro: `php artisan test tests/Feature/ExampleTest.php`.

- [ ] **[LOW] AdminUserSeeder использует env() напрямую** (`database/seeders/AdminUserSeeder.php:20-21`) (L1)
  - При `php artisan config:cache` функция `env()` вне config-файлов возвращает null → сидер пропустит создание админа.
  - На практике порядок установки (README §7: seed до кэша конфигов) это обходит, поэтому severity LOW.
  - Рекомендация: документировать «сидеть до config:cache» (есть в README) или читать через config(). Не блокер.

## Вердикт

**Релиз-готов. Блокеров нет.** Все 9 hard-инвариантов — PASS. Все критерии приёмки спеки §9 — выполнены (проверены кодом/поведением; реальный dump/restore не запускался из-за отсутствия pg-бинарей, но логика, флаги и обработка FATAL проверены по коду). MVP-тесты (dueForBackup, backups:dispatch) написаны и зелёные.

Тесты: 38 всего, 37 PASS. Единственный FAIL — устаревший стоковый ExampleTest (ложноположительный, поведение приложения корректно).

Баги по приоритету:
- CRITICAL/HIGH/MEDIUM: нет.
- LOW: B1 (стоковый ExampleTest надо привести в соответствие с редиректом), L1 (env() в сидере + config:cache — задокументировать).
