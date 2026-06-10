---
name: project-bootstrap
description: Bootstrap facts for the backup_panel Laravel build — version, queue tables, config layout, local DB constraint
metadata:
  type: project
---

Z1 bootstrap done: Laravel Framework 13.14.0 (composer constraint `laravel/framework: ^13.8`). `league/flysystem-aws-s3-v3: ^3.0` installed.

**Queue tables:** Laravel 13 ships ALL queue tables (`jobs`, `job_batches`, `failed_jobs`) in a single migration `database/migrations/0001_01_01_000002_create_jobs_table.php`. Do NOT run `php artisan queue:table` / `queue:failed-table` — they don't exist / would duplicate. This caught me off guard vs older Laravel.

**PG params config:** `config/backup.php` holds `backup.pg.{host,port,user,password}`, `backup.disk` (default `s3`), `backup.prefix` (`postgres-backups`), `backup.download_url_ttl` (15 min). BackupService must read PG creds from `config('backup.pg.*')`, never getenv. Password reaches processes via PGPASSWORD env only.

**S3 disk:** `config/filesystems.php` s3 disk has `endpoint` defaulting to `https://hel1.your-objectstorage.com` and `use_path_style_endpoint` defaulting to `true` (Hetzner requires path-style).

**Local DB constraint:** `.env` has `DB_CONNECTION=pgsql` pointing at `10.0.0.3` (prod DB server) which is UNREACHABLE from the dev box, and psql/pg_dump are NOT installed locally (only on prod). To validate migrations locally, run them against a throwaway sqlite db via env override:
`DB_CONNECTION=sqlite DB_DATABASE=/tmp/x.sqlite php artisan migrate --force` — do NOT migrate against the real pgsql connection locally.

**Protected files (never overwrite):** `CLAUDE.md`, `backup_panel_spec_for_claude_cli.md`, `.codegraph/`, `.claude/`. Existing `.gitignore` already covered `.env`, `vendor`, `node_modules`, `storage/app/tmp`, `.codegraph/`.
