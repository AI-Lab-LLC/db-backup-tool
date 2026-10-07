# Backend-dev memory index

- [Project bootstrap](project_bootstrap.md) — Laravel 13.14, config/backup.php, sqlite migrate workaround; GOTCHA compiled views в git
- [Schema decisions](schema_decisions.md) — status/trigger are string cols (not DB enums, validate in app), dueForBackup interval=0 semantics, relations by database_name
- [Auth (Breeze)](auth_breeze.md) — registration removed, AdminUserSeeder from env, post-login → /backups via repointed dashboard route
- [BackupService](backup_service.md) — PGPASSWORD только в env makeProcess (protected); PG_BIN_DIR+-w, pg_restore --list перед upload, upload verify, pruneOld все статусы+delete-confirm, markStaleRunning
- [Job & dispatch](job_command_dispatch.md) — RunBackupJob ShouldBeUnique(db), dispatch skip running+backoff, backups:prune/check-stale, alerts; unique-lock gotcha в тестах
- [Controller & routes](controller_routes.md) — BackupController 6 методов, имена роутов backups.*; runNow→dispatchIfIdle, destroy→BackupService::deleteBackup
- [Docker & infra](docker_infra.md) — панель докеризована, цепляется к external net workspace-net (postgres:17, MinIO) из ~/workspace/infra; db-init/s3-init + web/queue/scheduler
- [Restore queue & locks](restore_queue_locks.md) — RunRestoreJob/restores table, backup-vs-restore lock scheme, timeout budget 5400/5600/5700/150m
