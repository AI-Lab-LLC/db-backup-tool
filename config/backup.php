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
    | Admin account (seeded by AdminUserSeeder)
    |--------------------------------------------------------------------------
    |
    | Registration is disabled (internal tool), so the first administrator is
    | provisioned from these values. Read through config (not env() directly)
    | so the seeder still works under `config:cache`.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
