<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL connection for dump / restore
    |--------------------------------------------------------------------------
    |
    | These values are used by App\Services\BackupService when shelling out to
    | pg_dump / pg_restore / psql. The PostgreSQL server lives on a separate
    | host (10.0.0.3), so all calls go over the network with -h PG_HOST.
    |
    | IMPORTANT: the password is read here and passed to processes via the
    | PGPASSWORD environment variable only — never as a CLI argument (it would
    | show up in `ps`).
    |
    */

    'pg' => [
        'host' => env('PG_HOST', '10.0.0.3'),
        'port' => env('PG_PORT', 5432),
        'user' => env('PG_USER', 'backup_admin'),
        'password' => env('PG_PASSWORD', ''),

        // Directory holding pg_dump / pg_restore / psql. Empty = bare names via
        // PATH. The client major version must match the server (PG 17); on prod
        // the default PATH resolves to client 18, so point this at
        // /usr/lib/postgresql/17/bin there.
        'bin_dir' => env('PG_BIN_DIR', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | The S3 disk used for backups and the key prefix under which dumps are
    | stored: postgres-backups/{db}/{db}_{timestamp}.dump
    |
    */

    'disk' => env('BACKUP_DISK', 's3'),

    'prefix' => env('BACKUP_PREFIX', 'postgres-backups'),

    /*
    |--------------------------------------------------------------------------
    | Presigned download URL lifetime (minutes)
    |--------------------------------------------------------------------------
    */

    'download_url_ttl' => env('BACKUP_DOWNLOAD_URL_TTL', 15),

    /*
    |--------------------------------------------------------------------------
    | Temp directory for dumps / restores
    |--------------------------------------------------------------------------
    |
    | pg_dump writes here before upload and restores are staged here. Must have
    | room for the largest dump. On Forge, point this OUTSIDE the release
    | directory (e.g. /home/forge/backup-tmp) so deploys don't swap it away
    | mid-backup.
    |
    */

    'tmp_dir' => env('BACKUP_TMP_DIR') ?: storage_path('app/tmp'),

    /*
    |--------------------------------------------------------------------------
    | Failure alerts (opt-in)
    |--------------------------------------------------------------------------
    |
    | Backup failures and stale databases are always logged (Log::error). When
    | set, an e-mail is also sent to this address via the configured mailer.
    |
    */

    'alert_email' => env('BACKUP_ALERT_EMAIL') ?: null,

    /*
    |--------------------------------------------------------------------------
    | Admin account (seeded by AdminUserSeeder)
    |--------------------------------------------------------------------------
    |
    | Registration is disabled (internal tool), so the first administrator is
    | provisioned from these values. Read through config (not env() directly)
    | so the seeder still works under `config:cache`.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Panel access control
    |--------------------------------------------------------------------------
    |
    | allowed_ips: comma-separated IPs / CIDRs allowed to reach ANY web route
    | (login included), enforced by App\Http\Middleware\RestrictPanelIps.
    | Empty = allow all. Trusted proxies: config/trustedproxy.php.
    |
    */

    'allowed_ips' => env('PANEL_ALLOWED_IPS', ''),

    'admin' => [
        'name' => env('ADMIN_NAME', 'Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
