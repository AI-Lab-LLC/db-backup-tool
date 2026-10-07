<?php

namespace App\Services;

use App\Models\Backup;
use App\Models\Restore;
use App\Notifications\BackupAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Operational alerts for backups. Always logs (Log::error); additionally e-mails
 * config('backup.alert_email') when set (opt-in via BACKUP_ALERT_EMAIL).
 *
 * Alerting must never affect backup / prune outcomes: every send is wrapped in
 * try/catch and failures are only logged.
 */
class BackupAlerter
{
    public function backupFailed(Backup $backup): void
    {
        $this->alert(
            "[backup-panel] Backup FAILED: {$backup->database_name}",
            [
                "Database: {$backup->database_name}",
                "Backup #{$backup->id} ({$backup->trigger})",
                'Started: ' . ($backup->started_at?->toDateTimeString() ?? '-'),
                'Error: ' . ($backup->error ?: '-'),
            ],
            ['backup_id' => $backup->id, 'database' => $backup->database_name],
        );
    }

    public function jobFailed(string $database, string $trigger, Throwable $e): void
    {
        $this->alert(
            "[backup-panel] Backup job FAILED: {$database}",
            [
                "Database: {$database}",
                "Trigger: {$trigger}",
                'Error: ' . $e->getMessage(),
            ],
            ['database' => $database, 'exception' => $e::class],
        );
    }

    public function restoreFailed(Restore $restore): void
    {
        $this->alert(
            "[backup-panel] Restore FAILED: {$restore->target_database}",
            [
                "Target database: {$restore->target_database}",
                "Source: {$restore->database_name} (backup #" . ($restore->backup_id ?? '-') . ')',
                "Restore #{$restore->id}, requested by user #" . ($restore->user_id ?? '-'),
                'Started: ' . ($restore->started_at?->toDateTimeString() ?? '-'),
                'Error: ' . ($restore->error ?: '-'),
            ],
            ['restore_id' => $restore->id, 'target' => $restore->target_database, 'backup_id' => $restore->backup_id],
        );
    }

    public function databaseDisabled(string $database, ?string $byEmail): void
    {
        $by = $byEmail ?? 'unknown user';

        $this->alert(
            "[backup-panel] Backups disabled: {$database}",
            [
                "Database {$database} disabled by {$by}.",
                'Scheduled backups for it will no longer run until it is re-enabled.',
            ],
            ['database' => $database, 'by' => $by],
        );
    }

    public function staleDatabase(string $database, int $intervalMinutes, ?string $lastSuccessAt): void
    {
        $this->alert(
            "[backup-panel] No recent backup: {$database}",
            [
                "Database: {$database}",
                "Interval: {$intervalMinutes} min (alert threshold: " . ($intervalMinutes * 2) . ' min)',
                'Last successful backup: ' . ($lastSuccessAt ?? 'never'),
            ],
            ['database' => $database, 'last_success_at' => $lastSuccessAt],
        );
    }

    /**
     * @param string[] $lines
     * @param array<string, mixed> $context
     */
    public function alert(string $subject, array $lines, array $context = []): void
    {
        try {
            Log::error($subject, $context + ['details' => $lines]);
        } catch (Throwable) {
            // logging must never break the caller
        }

        $email = config('backup.alert_email');

        if (! is_string($email) || trim($email) === '') {
            return;
        }

        try {
            Notification::route('mail', trim($email))->notify(new BackupAlert($subject, $lines));
        } catch (Throwable $e) {
            try {
                Log::warning('Backup alert e-mail could not be sent', [
                    'subject' => $subject,
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable) {
                // swallow
            }
        }
    }
}
