---
name: schema-decisions
description: Z2/Z3 schema choices — status/trigger are string columns (not DB enums), dueForBackup interval=0 semantics, model relations by database_name
metadata:
  type: project
---

Migrations (backup_configs, backups) and models (BackupConfig, Backup) follow spec §4 literally, with these deliberate choices:

- **status / trigger are `string` columns with string defaults** (`pending`, `manual`), NOT `$table->enum()`. Spec §4 calls them enums, but task required DB-agnostic migrations (must run on sqlite for test + pgsql in prod). Laravel `enum()` emits a CHECK constraint on pg and diverges on sqlite, and extending the value list would force a migration.
  - **Why:** DB-agnostic requirement + flexibility.
  - **How to apply:** allowed values (status: pending|running|success|failed, trigger: manual|scheduled) must be enforced in the app layer — validation in `BackupController`/form requests and when `BackupService`/`RunBackupJob` write status. Don't rely on the DB to reject bad values.

- **dueForBackup(): interval_minutes === 0 → false** (manual-only). Spec §4 marks "0 = только вручную"; §5 only gives the `last_run_at + interval_minutes` formula. Logic: false if !enabled or interval===0; true if last_run_at null; else last_run_at+interval <= now().

- **Relations are by `database_name`, not FK id** — no FK between backups and backup_configs in the schema. `BackupConfig::backups()` hasMany, `Backup::config()` belongsTo, both keyed on `database_name`.

- `last_run_at` is only READ in dueForBackup(); never written in the model. Mutation stays in RunBackupJob (hard-invariant). See [[project_bootstrap]].
