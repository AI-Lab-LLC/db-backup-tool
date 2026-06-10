<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    protected $fillable = [
        'database_name',
        'filename',
        's3_path',
        'size_bytes',
        'status',
        'trigger',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'size_bytes' => 'int',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * The backup config this backup belongs to (matched by database name).
     */
    public function config(): BelongsTo
    {
        return $this->belongsTo(BackupConfig::class, 'database_name', 'database_name');
    }
}
