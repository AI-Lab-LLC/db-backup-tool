# Backend-dev memory index

- [Project bootstrap](project_bootstrap.md) — Laravel 13.14, queue tables in one migration, config/backup.php for PG creds, local sqlite migrate workaround, protected files
- [Schema decisions](schema_decisions.md) — status/trigger are string cols (not DB enums, validate in app), dueForBackup interval=0 semantics, relations by database_name
- [Auth (Breeze)](auth_breeze.md) — registration removed, AdminUserSeeder from env, post-login → /backups via repointed dashboard route
- [BackupService](backup_service.md) — реализован: PGPASSWORD только в env makeProcess, S3-ключ prefix/db/file, restore-провал по FATAL, pruneOld success-only
- [Job & dispatch](job_command_dispatch.md) — RunBackupJob (timeout 3700, last_run_at только тут) + backups:dispatch + Schedule everyMinute; как тестить на sqlite/Bus::fake
- [Controller & routes](controller_routes.md) — BackupController 6 методов, имена роутов backups.*, поля форм, переменные view, декаплинг (бэкап только через Job)
- [Docker & infra](docker_infra.md) — панель докеризована, цепляется к external net workspace-net (postgres:17, MinIO) из ~/workspace/infra; db-init/s3-init + web/queue/scheduler
