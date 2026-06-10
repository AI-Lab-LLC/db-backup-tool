---
name: project-test-setup
description: How to run tests for the backup_panel project — phpunit sqlite config, pg-binary absence, key test files
metadata:
  type: project
---

Backup Panel (Laravel) QA test setup.

- `phpunit.xml` already sets `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `QUEUE_CONNECTION=sync`. So `php artisan test` runs against in-memory sqlite out of the box — no inline env needed for the suite.
- For ad-hoc migrate/seed against a file DB use inline env: `DB_CONNECTION=sqlite DB_DATABASE=/tmp/qa.sqlite php artisan migrate --force`. Never touch the real `.env` (pgsql 10.0.0.3) or pgsql server.
- **pg binaries (psql/pg_dump/pg_restore) are NOT installed locally** — real backup/restore cannot run. Verify `BackupService::backup/restore/listDatabases` by reading code + flags, not execution.
- Unit tests that touch Eloquent models (even unsaved instances with casts / `now()`) must extend `Tests\TestCase`, NOT bare `PHPUnit\Framework\TestCase` — otherwise "Call to a member function connection() on null".
- Existing tests: `BackupServicePruneTest` (prune logic, Storage::fake), `BackupRoutesAuthTest` (guest redirects). I added `tests/Unit/DueForBackupTest.php` and `tests/Feature/DispatchScheduledBackupsTest.php` (Bus::fake).

**Why:** captures non-obvious env wiring and the pg-binary constraint so future QA cycles don't re-discover them.
**How to apply:** start QA cycles by running `php artisan test`; for backup/restore correctness, audit code rather than expecting it to execute.
