---
name: job-command-dispatch
description: RunBackupJob + DispatchScheduledBackups + scheduler реализованы (Z6/Z7), как тестировать локально
metadata:
  type: project
---

Z6/Z7 реализованы.

- `app/Jobs/RunBackupJob.php` — ShouldQueue, $timeout=3700, $tries=2; конструктор `(string $database, string $trigger='manual')`. handle(BackupService $service): backup() → при status==='success' находит BackupConfig по database_name, update(['last_run_at'=>now()]) и pruneOld(database, retention_days). last_run_at мутируется ТОЛЬКО тут (hard invariant подтверждён grep).
- `app/Console/Commands/DispatchScheduledBackups.php` — signature `backups:dispatch`. handle(): enabled-конфиги, dueForBackup() → RunBackupJob::dispatch(database_name,'scheduled'), логирует "Dispatched N ...". Только диспатчит.
- Расписание в `routes/console.php`: `Schedule::command('backups:dispatch')->everyMinute();` (use Illuminate\Support\Facades\Schedule).

**Why:** layer decoupling — команда не запускает процессы, last_run_at только в Job.

**How to apply (локальные проверки без PG):** pg-бинари и PG 10.0.0.3 недоступны. Любой artisan, читающий БД/кэш, падает с timeout на pgsql. Гонять с override:
`DB_CONNECTION=sqlite DB_DATABASE=$(pwd)/database/database.sqlite CACHE_STORE=array QUEUE_CONNECTION=sync php artisan ...`
- `schedule:list` так показывает backups:dispatch everyMinute.
- Для проверки диспатча: НЕ запускать job реально (backup() пойдёт в PG). Bus::fake() + Artisan::call('backups:dispatch') через standalone-скрипт в корне проекта (bootstrap/app.php + kernel->bootstrap()), не tinker (tinker эхает файл, а не исполняет). Скрипт удалять после прогона.
- sqlite-файл `database/database.sqlite` уже есть; миграции backup_configs/backups в нём. Таблицы очередей (jobs) для sync-теста не нужны.
