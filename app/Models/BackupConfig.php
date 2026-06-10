<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackupConfig extends Model
{
    protected $fillable = [
        'database_name',
        'label',
        'enabled',
        'interval_minutes',
        'retention_days',
        'last_run_at',
    ];

    protected $casts = [
        'enabled' => 'bool',
        'interval_minutes' => 'int',
        'retention_days' => 'int',
        'last_run_at' => 'datetime',
    ];

    /**
     * Whether this config is due for an automatic backup.
     *
     * Manual-only configs (interval_minutes === 0) and disabled configs are
     * never due. A config that has never run is due immediately; otherwise it
     * is due once last_run_at + interval_minutes has passed.
     */
    public function dueForBackup(): bool
    {
        if (! $this->enabled || $this->interval_minutes === 0) {
            return false;
        }

        if ($this->last_run_at === null) {
            return true;
        }

        return $this->last_run_at->copy()->addMinutes($this->interval_minutes)->lessThanOrEqualTo(now());
    }

    /**
     * History of backups for this config's database.
     */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class, 'database_name', 'database_name');
    }
}
