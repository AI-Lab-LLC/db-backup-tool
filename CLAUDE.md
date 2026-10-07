# Backup Panel — project instructions

PostgreSQL **backup panel** on Laravel. The authoritative spec is
[`backup_panel_spec_for_claude_cli.md`](./backup_panel_spec_for_claude_cli.md) —
when behaviour is in question, that file wins. This is a **greenfield** build: if
CodeGraph returns little, the code isn't written yet — that's expected, not a
broken index. Don't re-init; read the spec and implement.

## Stack (use exactly this)
- Laravel (latest stable), Blade + Tailwind UI (CDN OK for MVP), Laravel Breeze (blade) auth.
- Queue driver `database`; Scheduler via `schedule:run` cron every minute.
- S3 via `league/flysystem-aws-s3-v3 ^3.0`, disk `s3`, `use_path_style_endpoint => true`, endpoint `https://hel1.your-objectstorage.com`.
- Shell-outs via `Symfony\Component\Process\Process`.

## Infrastructure
- Panel runs on the **site server** (`10.0.0.2`). PostgreSQL **17** is on a **separate DB server** (`10.0.0.3`).
- All `pg_dump` / `pg_restore` / `psql` calls go over the network with `-h 10.0.0.3`.
- Client `postgresql-client-17` must match the server major version.

## Hard invariants (do not violate)
- **`PGPASSWORD` via process env only — never in CLI argv** (it shows in `ps`).
- **Layer decoupling**: controllers and jobs call into `App\Services\BackupService`; they never run processes directly. `backups.last_run_at` is mutated **only** by `RunBackupJob` (after a successful backup, which then calls `pruneOld()`).
- **Backups run in a queued Job** (`RunBackupJob`, `ShouldQueue`, timeout 5400 — retry_after 5700, unique lock 5600, stale 150 min; see RunBackupJob), never in a web request.
- **Restore is destructive**: requires an explicit confirmation checkbox + warning before submit; validate database names and the restore target. `restore()` uses `pg_restore --clean --if-exists --no-owner`; treat it as failed **only when stderr contains `FATAL`** (warnings on stderr are normal).
- `backup()` dumps custom format (`-Fc`) to `storage/app/tmp`, uploads to S3 key `postgres-backups/{db}/{db}_{timestamp}.dump`, and deletes the temp file in a `finally`.
- Retention cleanup (`pruneOld`) runs after each successful backup.
- All panel routes sit behind `auth` middleware; registration routes are disabled (internal tool, accounts seeded via `AdminUserSeeder`). Never publish the port publicly.
- Secrets live only in `.env` (gitignored).

## Key components (spec §5)
- Models `BackupConfig` (`dueForBackup()`), `Backup`.
- `BackupService`: `listDatabases`, `backup`, `downloadUrl` (presigned ~15 min), `restore`, `pruneOld`.
- `RunBackupJob`, command `DispatchScheduledBackups` (`backups:dispatch`, `Schedule::command(...)->everyMinute()`).
- `BackupController` + `routes/web.php` (auth group), view `resources/views/backups/index.blade.php`.

## Agent team & orchestration
Subagents **cannot launch other subagents** — orchestration happens here, in the
main session. Route work as:
- `backend-dev` — Laravel backend (migrations, models, services, jobs, commands, controllers). Investigates with CodeGraph, then implements.
- `frontend-dev` — Blade + Tailwind + Alpine UI and its controller/route wiring.
- `qa-tester` — tests changes, writes `checklist.md` for bugs, surfaces it back here.
- `task-planner` — investigates and produces a dispatch-ready plan (writes no code); hand its approved plan back here to dispatch.

After backend changes, run `php artisan migrate` / tests; after UI changes, `npm run build`. CodeGraph lags writes ~500ms — don't re-query the same turn you edit.
