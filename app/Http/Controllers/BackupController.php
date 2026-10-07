<?php

namespace App\Http\Controllers;

use App\Jobs\RunBackupJob;
use App\Jobs\RunRestoreJob;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Restore;
use App\Services\BackupAlerter;
use App\Services\BackupService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Web entry point for the backup panel.
 *
 * HARD INVARIANTS honoured here:
 * - This controller NEVER runs processes (pg_dump/pg_restore/psql) directly and
 *   NEVER calls BackupService::backup() in a web request. Backups go through the
 *   queued RunBackupJob only.
 * - This controller NEVER mutates backups/configs `last_run_at` — that is owned
 *   exclusively by RunBackupJob.
 * - Restore is destructive: it requires an explicit confirmation checkbox plus a
 *   validated, allowed target database name (plus a second confirmation when the
 *   target differs from the dump's source database). It is QUEUED (RunRestoreJob),
 *   never run in the web request.
 * - Mutating actions are audit-logged (Log::info 'audit: ...').
 */
class BackupController extends Controller
{
    /** Cache key / TTL for the server database list shown on the index page only. */
    public const DATABASES_CACHE_KEY = 'backups:server-databases';

    public const DATABASES_CACHE_TTL = 60;
    /**
     * Panel landing page: configs, history, and auto-discovered databases.
     *
     * Supports optional, read-only filters on the history list (database name,
     * created_at date range). Both $service and $request are resolved from the
     * container. No schema changes — filters only narrow the SELECT.
     */
    public function index(Request $request, BackupService $service): View
    {
        $filters = $request->validate([
            'database' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $filterDatabase = $filters['database'] ?? null;
        $filterDateFrom = $filters['date_from'] ?? null;
        $filterDateTo = $filters['date_to'] ?? null;

        $configs = BackupConfig::query()
            ->orderBy('database_name')
            ->get();

        $backups = Backup::query()
            ->when($filterDatabase, fn ($q, $database) => $q->where('database_name', $database))
            ->when($filterDateFrom, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filterDateTo, fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        // Database names for the filter dropdown: every database seen in history
        // merged with every configured database, de-duped and sorted.
        $databaseOptions = Backup::query()
            ->select('database_name')
            ->distinct()
            ->pluck('database_name')
            ->merge($configs->pluck('database_name'))
            ->unique()
            ->sort()
            ->values()
            ->all();

        // Auto-discovered databases minus the ones already configured. The
        // PostgreSQL server may be unreachable locally; never let that 500 the
        // page — degrade to an empty list with a warning flash.
        // Cached for 60s (index page only; runNow / restore validate against a
        // fresh listDatabases()). A failure is not cached.
        $discovered = [];
        $serverDatabases = [];
        try {
            $serverDatabases = Cache::remember(
                self::DATABASES_CACHE_KEY,
                self::DATABASES_CACHE_TTL,
                fn () => $service->listDatabases(),
            );
            $configured = $configs->pluck('database_name')->all();
            $discovered = array_values(array_diff($serverDatabases, $configured));
        } catch (Throwable $e) {
            session()->flash('warning', 'Не удалось получить список баз с PostgreSQL: ' . $e->getMessage());
        }

        $restores = Restore::query()
            ->with('backup')
            ->latest('id')
            ->limit(10)
            ->get();

        return view('backups.index', [
            'configs' => $configs,
            'backups' => $backups,
            'discovered' => $discovered,
            'databaseOptions' => $databaseOptions,
            'filterDatabase' => $filterDatabase,
            'filterDateFrom' => $filterDateFrom,
            'filterDateTo' => $filterDateTo,
            'restores' => $restores,
            'serverDatabases' => $serverDatabases,
            // Server databases minus the static denylist (postgres, templates,
            // the panel's own DB) — for the restore target picker.
            'restoreTargets' => array_values(array_filter(
                $serverDatabases,
                fn ($db) => BackupService::staticRestoreTargetError((string) $db) === null,
            )),
        ]);
    }

    /**
     * Create or update a per-database backup config (matched by database_name).
     */
    public function saveConfig(Request $request, BackupAlerter $alerter): RedirectResponse
    {
        $data = $request->validate([
            'database_name' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:255'],
            'enabled' => ['boolean'],
            'interval_minutes' => ['required', 'integer', 'min:0'],
            'retention_days' => ['required', 'integer', 'min:0'],
        ]);

        $data['enabled'] = $request->boolean('enabled');

        $wasEnabled = BackupConfig::query()
            ->where('database_name', $data['database_name'])
            ->value('enabled');

        BackupConfig::updateOrCreate(
            ['database_name' => $data['database_name']],
            $data,
        );

        $this->audit($request, 'save_config', [
            'database' => $data['database_name'],
            'enabled' => $data['enabled'],
            'interval_minutes' => (int) $data['interval_minutes'],
            'retention_days' => (int) $data['retention_days'],
        ]);

        // Only an existing config flipping true -> false (not a new disabled one).
        if ((bool) $wasEnabled && ! $data['enabled']) {
            $alerter->databaseDisabled($data['database_name'], $request->user()?->email);
        }

        return back()->with('status', "Настройки для «{$data['database_name']}» сохранены.");
    }

    /**
     * Queue a manual backup. Backups run only via RunBackupJob — never inline.
     */
    public function runNow(Request $request, BackupService $service): RedirectResponse
    {
        $request->validate([
            'database' => ['required', 'string', 'max:255'],
        ]);

        $database = $request->string('database')->toString();

        // The target must be a known database: either already configured, or
        // present among the auto-discovered databases on the server.
        $known = BackupConfig::query()
            ->where('database_name', $database)
            ->exists();

        if (! $known) {
            try {
                $known = in_array($database, $service->listDatabases(), true);
            } catch (Throwable $e) {
                // If we cannot reach PostgreSQL we cannot verify; reject safely.
                return back()->with('error', 'Не удалось проверить базу на сервере PostgreSQL: ' . $e->getMessage());
            }
        }

        if (! $known) {
            return back()->withErrors(['database' => "Неизвестная база «{$database}»."]);
        }

        // Backups and restores of the same database are mutually exclusive.
        if (Restore::activeFor($database)) {
            $this->audit($request, 'run_now', ['database' => $database, 'result' => 'rejected_restore_active']);

            return back()->with('error', "Идёт восстановление в базу «{$database}» — бэкап сейчас невозможен, попробуйте позже.");
        }

        // ShouldBeUnique would silently drop a duplicate dispatch, so check for
        // an in-flight row and take the unique lock explicitly to report honestly.
        $inFlight = Backup::query()
            ->where('database_name', $database)
            ->where('status', 'running')
            ->exists();

        if ($inFlight || ! RunBackupJob::dispatchIfIdle($database, 'manual')) {
            $this->audit($request, 'run_now', ['database' => $database, 'result' => 'rejected_in_flight']);

            return back()->with('error', "Бэкап «{$database}» уже выполняется или стоит в очереди.");
        }

        $this->audit($request, 'run_now', ['database' => $database, 'result' => 'queued']);

        return back()->with('status', "Бэкап базы «{$database}» поставлен в очередь.");
    }

    /**
     * Redirect to a short-lived presigned S3 URL for the dump.
     */
    public function download(Backup $backup, BackupService $service): RedirectResponse
    {
        return redirect()->away($service->downloadUrl($backup));
    }

    /**
     * Queue a restore of a dump into a target database (RunRestoreJob).
     *
     * Destructive — guarded by: a successful source backup; an explicit
     * `confirm` checkbox; a validated `target_database`; a second
     * `confirm_mismatch` checkbox when the target differs from the dump's source
     * database; BackupService::restoreTargetError() (denylist incl. the panel's
     * own DB, existence on the server); and no backup or other restore of the
     * target being queued/running.
     */
    public function restore(Request $request, Backup $backup, BackupService $service): RedirectResponse
    {
        if ($backup->status !== 'success') {
            return back()->with('error', "Восстановить можно только успешный бэкап (статус #{$backup->id}: {$backup->status}).");
        }

        $validated = $request->validate([
            'confirm' => ['accepted'],
            'target_database' => ['required', 'string', 'max:63', 'regex:/^[A-Za-z0-9_]+$/'],
        ]);

        $target = $validated['target_database'];

        if ($target !== $backup->database_name) {
            $request->validate(
                ['confirm_mismatch' => ['accepted']],
                ['confirm_mismatch.accepted' => "Целевая база «{$target}» отличается от исходной «{$backup->database_name}» — подтвердите восстановление в другую базу."],
            );
        }

        $context = ['backup_id' => $backup->id, 'source' => $backup->database_name, 'target' => $target];

        $reason = $service->restoreTargetError($target);

        if ($reason !== null) {
            $this->audit($request, 'restore', $context + ['result' => 'rejected_target', 'reason' => $reason]);

            return back()->with('error', "Восстановление в «{$target}» отклонено: {$reason}.");
        }

        // Checked BEFORE the backup lock probe: a running restore also holds
        // RunBackupJob's unique lock, which would otherwise be misreported as
        // "backup in progress".
        if (Restore::activeFor($target)) {
            $this->audit($request, 'restore', $context + ['result' => 'rejected_restore_active']);

            return back()->with('error', "Восстановление в базу «{$target}» уже выполняется или стоит в очереди.");
        }

        $backupBusy = Backup::query()
            ->where('database_name', $target)
            ->where('status', 'running')
            ->exists();

        if ($backupBusy || RunBackupJob::isQueuedOrRunning($target)) {
            $this->audit($request, 'restore', $context + ['result' => 'rejected_backup_active']);

            return back()->with('error', "Бэкап базы «{$target}» выполняется или стоит в очереди — восстановление сейчас невозможно, попробуйте позже.");
        }

        $restore = Restore::create([
            'backup_id' => $backup->id,
            'database_name' => $backup->database_name,
            'target_database' => $target,
            'status' => Restore::STATUS_QUEUED,
            'user_id' => $request->user()?->id,
        ]);

        try {
            $queued = RunRestoreJob::dispatchIfIdle($restore);
        } catch (Throwable $e) {
            // A queued row that never reached the queue would block backups and
            // restores of this database until markStaleRestores() (150 min).
            $restore->update([
                'status' => Restore::STATUS_FAILED,
                'error' => 'dispatch failed: ' . $e->getMessage(),
                'finished_at' => now(),
            ]);

            $this->audit($request, 'restore', $context + ['restore_id' => $restore->id, 'result' => 'dispatch_failed', 'error' => $e->getMessage()]);

            return back()->with('error', 'Не удалось поставить восстановление в очередь: ' . $e->getMessage());
        }

        if (! $queued) {
            $restore->update([
                'status' => Restore::STATUS_FAILED,
                'error' => "another restore into {$target} is already queued or running",
                'finished_at' => now(),
            ]);

            $this->audit($request, 'restore', $context + ['restore_id' => $restore->id, 'result' => 'rejected_restore_active']);

            return back()->with('error', "Восстановление в базу «{$target}» уже выполняется или стоит в очереди.");
        }

        $this->audit($request, 'restore', $context + ['restore_id' => $restore->id, 'result' => 'queued']);

        return back()->with('status', 'Восстановление поставлено в очередь');
    }

    /**
     * Delete a backup from S3 and remove its history record — via the service,
     * which only drops the row once the S3 object is confirmed gone and never
     * deletes an object another row still references.
     */
    public function destroy(Request $request, Backup $backup, BackupService $service): RedirectResponse
    {
        $context = ['backup_id' => $backup->id, 'database' => $backup->database_name, 's3_path' => $backup->s3_path];

        if ($backup->status === 'running') {
            $this->audit($request, 'destroy', $context + ['result' => 'rejected_running']);

            return back()->with('error', 'Нельзя удалить бэкап, который ещё выполняется.');
        }

        if (Restore::query()->active()->where('backup_id', $backup->id)->exists()) {
            $this->audit($request, 'destroy', $context + ['result' => 'rejected_restore_active']);

            return back()->with('error', 'Нельзя удалить бэкап, из которого сейчас идёт восстановление.');
        }

        if (! $service->deleteBackup($backup)) {
            $this->audit($request, 'destroy', $context + ['result' => 'failed_s3_delete']);

            return back()->with('error', "Не удалось удалить файл бэкапа из S3 ({$backup->s3_path}); запись сохранена, попробуйте ещё раз.");
        }

        $this->audit($request, 'destroy', $context + ['result' => 'deleted']);

        return back()->with('status', 'Бэкап удалён.');
    }

    /**
     * Audit trail for mutating panel actions: who, what, from where.
     *
     * @param array<string, mixed> $context
     */
    private function audit(Request $request, string $action, array $context): void
    {
        Log::info("audit: {$action}", [
            'user_id' => $request->user()?->id,
            'email' => $request->user()?->email,
            'ip' => $request->ip(),
        ] + $context);
    }
}
