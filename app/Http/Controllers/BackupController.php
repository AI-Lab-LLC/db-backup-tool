<?php

namespace App\Http\Controllers;

use App\Jobs\RunBackupJob;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Services\BackupService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
 *   validated target database name.
 */
class BackupController extends Controller
{
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
        $discovered = [];
        try {
            $configured = $configs->pluck('database_name')->all();
            $discovered = array_values(array_diff($service->listDatabases(), $configured));
        } catch (Throwable $e) {
            session()->flash('warning', 'Не удалось получить список баз с PostgreSQL: ' . $e->getMessage());
        }

        return view('backups.index', [
            'configs' => $configs,
            'backups' => $backups,
            'discovered' => $discovered,
            'databaseOptions' => $databaseOptions,
            'filterDatabase' => $filterDatabase,
            'filterDateFrom' => $filterDateFrom,
            'filterDateTo' => $filterDateTo,
        ]);
    }

    /**
     * Create or update a per-database backup config (matched by database_name).
     */
    public function saveConfig(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'database_name' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:255'],
            'enabled' => ['boolean'],
            'interval_minutes' => ['required', 'integer', 'min:0'],
            'retention_days' => ['required', 'integer', 'min:0'],
        ]);

        $data['enabled'] = $request->boolean('enabled');

        BackupConfig::updateOrCreate(
            ['database_name' => $data['database_name']],
            $data,
        );

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

        RunBackupJob::dispatch($database, 'manual');

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
     * Restore a dump into a target database. Destructive — guarded by an explicit
     * confirmation checkbox and a validated target name. Runs synchronously via
     * the service (per spec §5), never spawning processes in the controller.
     */
    public function restore(Request $request, Backup $backup, BackupService $service): RedirectResponse
    {
        $validated = $request->validate([
            'confirm' => ['accepted'],
            'target_database' => ['required', 'string', 'regex:/^[A-Za-z0-9_]+$/'],
        ]);

        $target = $validated['target_database'];

        try {
            $service->restore($backup, $target);
        } catch (Throwable $e) {
            // Includes FATAL restore errors and invalid-target rejections.
            return back()->with('error', "Восстановление в «{$target}» не удалось: " . $e->getMessage());
        }

        return back()->with('status', "Бэкап восстановлен в базу «{$target}».");
    }

    /**
     * Delete a backup from S3 and remove its history record. No processes are
     * touched — this is a plain storage/record delete, so it stays in the
     * controller without breaking layer decoupling.
     */
    public function destroy(Backup $backup): RedirectResponse
    {
        if ($backup->s3_path) {
            Storage::disk(config('backup.disk', 's3'))->delete($backup->s3_path);
        }

        $backup->delete();

        return back()->with('status', 'Бэкап удалён.');
    }
}
