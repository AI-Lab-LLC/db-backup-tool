---
name: job-command-dispatch
description: RunBackupJob + DispatchScheduledBackups + scheduler реализованы (Z6/Z7), как тестировать локально
metadata:
  type: project
---

Z6/Z7 реализованы.

- `app/Jobs/RunBackupJob.php` — ShouldQueue, $timeout=5400 (было 3700), $tries=2; конструктор `(string $database, string $trigger='manual')`. handle(BackupService $service): backup() → при status==='success' находит BackupConfig по database_name, update(['last_run_at'=>now()]) и pruneOld(database, retention_days). last_run_at мутируется ТОЛЬКО тут (hard invariant подтверждён grep).
- `app/Console/Commands/DispatchScheduledBackups.php` — signature `backups:dispatch`. handle(): enabled-конфиги, dueForBackup() → RunBackupJob::dispatch(database_name,'scheduled'), логирует "Dispatched N ...". Только диспатчит.
- Расписание в `routes/console.php`: `Schedule::command('backups:dispatch')->everyMinute();` (use Illuminate\Support\Facades\Schedule).

**Why:** layer decoupling — команда не запускает процессы, last_run_at только в Job.

**How to apply (локальные проверки без PG):** pg-бинари и PG 10.0.0.3 недоступны. Любой artisan, читающий БД/кэш, падает с timeout на pgsql. Гонять с override:
`DB_CONNECTION=sqlite DB_DATABASE=$(pwd)/database/database.sqlite CACHE_STORE=array QUEUE_CONNECTION=sync php artisan ...`
- `schedule:list` так показывает backups:dispatch everyMinute.
- Для проверки диспатча: НЕ запускать job реально (backup() пойдёт в PG). Bus::fake() + Artisan::call('backups:dispatch') через standalone-скрипт в корне проекта (bootstrap/app.php + kernel->bootstrap()), не tinker (tinker эхает файл, а не исполняет). Скрипт удалять после прогона.
- sqlite-файл `database/database.sqlite` уже есть; миграции backup_configs/backups в нём. Таблицы очередей (jobs) для sync-теста не нужны.

**С 2026-10-07:** RunBackupJob — ShouldBeUnique (uniqueId=database, uniqueFor=5600 (было 4000)), failed() → BackupAlerter; pruneOld после успеха в try/catch (иначе ретрай = второй дамп). backups:dispatch пропускает db с running-строкой и делает failure-backoff (последняя строка failed и created_at+interval > now); dueForBackup не менялся. Новые команды backups:prune (daily) и backups:check-stale (hourly); все schedule с withoutOverlapping(явный expiry, не дефолт 24ч). Алерты: App\Services\BackupAlerter → Log::error всегда + on-demand Notification BackupAlert если backup.alert_email.
GOTCHA тестов: PendingDispatch берёт unique-lock даже под Bus::fake() — повторный dispatch той же db в одном тесте отбрасывается. Notification::route — реальный static метод фасада, мокать надо 'send'.
GOTCHA очереди: config/queue.php database.retry_after по умолчанию теперь 5700 (с 2026-10-07; раньше 3900). При <5600 долгий бэкап перехватывается вторым воркером (двойной pg_dump, ложный failed-алерт). На проде проверить, что DB_QUEUE_RETRY_AFTER в .env не переопределяет его ниже 5700. Есть тест-страж в RunBackupJobTest.
**Honest dispatch:** `RunBackupJob::dispatchIfIdle(db, trigger): bool` берёт UniqueLock вручную и шлёт через Bus Dispatcher (он уникальность не проверяет; воркер/sync сам освобождает lock). Используют runNow и backups:dispatch — не возвращаться к голому ::dispatch(), он молча дропает дубль.
check-stale: троттлинг через Cache `backups:stale-alerted:{db}` = 'success:{id}'|'never', TTL 24ч; healthy → forget.
