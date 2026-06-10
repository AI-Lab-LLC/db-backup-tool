---
name: controller-routes
description: BackupController + routes/web.php — методы, имена роутов, поля форм, инварианты декаплинга
metadata:
  type: project
---

BackupController (app/Http/Controllers/BackupController.php) реализован; роуты в routes/web.php в группе middleware('auth') (+web).

Роуты (все auth): backups.index (GET /backups), backups.config (POST /backups/config), backups.run (POST /backups/run), backups.download (GET /backups/{backup}/download), backups.restore (POST /backups/{backup}/restore), backups.destroy (DELETE /backups/{backup}).

Декаплинг соблюдён: контроллер не вызывает Process/pg напрямую и НЕ вызывает $service->backup() в web — бэкап только RunBackupJob::dispatch($database,'manual'). last_run_at контроллер не трогает. BackupService инжектится через DI в методы (index, runNow, download, restore).

Поля форм (для frontend-вьюхи backups/index.blade.php):
- saveConfig: database_name(req), label(nullable), enabled(boolean), interval_minutes(req int min:0), retention_days(req int min:0)
- runNow: database (req, должна быть в configs или listDatabases)
- restore: confirm (accepted — обязателен), target_database (req, regex ^[A-Za-z0-9_]+$)
- destroy: DELETE с @method('DELETE')

Переменные во view('backups.index'): configs (Collection BackupConfig), backups (paginate(25) Backup, ->withQueryString()), discovered (array имён БД = listDatabases минус сконфигурированные; пусто+флеш 'warning' если pg недоступен), databaseOptions (array, F1), filterDatabase/filterDateFrom/filterDateTo (F1).

F1 — серверная фильтрация истории (read-only, схему НЕ меняли). index(Request $request, BackupService $service) — Request первым. Query-параметры: database (nullable|string|max:255), date_from (nullable|date), date_to (nullable|date|after_or_equal:date_from). date_from→created_at>=startOfDay, date_to→created_at<=endOfDay (включительно по дню). Carbon импортирован.
Переменные для фронтенда:
- $databaseOptions: отсортированный уникальный список имён баз = distinct database_name из backups + database_name из configs. Для селекта фильтра.
- $filterDatabase / $filterDateFrom / $filterDateTo: активные значения (null если не заданы) — для preselect формы. Фронтенд читает ИМЕННО эти имена; форма method=GET на backups.index.
Тест: tests/Feature/BackupIndexFilterTest.php (6 кейсов, sqlite :memory:).

Флеши: 'status' (успех), 'error' (ошибка restore/runNow), 'warning' (pg недоступен). Ошибки валидации через withErrors.

**Why:** Z8 — спека §5. **How to apply:** при правках UI/контроллера держать декаплинг; restore синхронный через сервис (как в спеке), не через job.

Тест: tests/Feature/BackupRoutesAuthTest.php — гость на backups.* → redirect /login (sqlite in-memory, 3 теста зелёные).
