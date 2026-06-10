---
name: backup-service
description: BackupService реализован — методы, инвариант PGPASSWORD-через-env, формат S3-ключа, где провал restore по FATAL
metadata:
  type: project
---

App\Services\BackupService реализован (Z4, 2026-06-05).

**Where:** app/Services/BackupService.php. Тесты: tests/Feature/BackupServicePruneTest.php (4 теста, RefreshDatabase + Storage::fake на config('backup.disk')).

**Методы:** listDatabases (psql -t -A -d postgres), backup(db, trigger='manual'), downloadUrl (temporaryUrl, ttl в МИНУТАХ — config download_url_ttl), restore(Backup, target), pruneOld(db, days).

**PGPASSWORD invariant:** все pg-команды через private makeProcess(array $command) — `new Process($command, null, ['PGPASSWORD' => config('backup.pg.password')])`, массив argv без шелла. Пароль НИКОГДА в connectionArgs() (там только -h/-p/-U). Это единственная точка запуска процессов.

**Why:** пароль в argv виден в `ps` — hard invariant из CLAUDE.md.

**Детали поведения:**
- S3-ключ: s3Key() → `{prefix}/{db}/{filename}`, prefix из config('backup.prefix')='postgres-backups'. filename = `{db}_{Ymd_His}.dump`.
- backup() НЕ трогает last_run_at и НЕ зовёт pruneOld (это RunBackupJob). Создаёт Backup status=running, заливает stream в S3, finally @unlink temp. Провал = !isSuccessful || stderr содержит FATAL.
- restore() провал ТОЛЬКО при FATAL в stderr (warnings норма). Валидирует target по /^[A-Za-z0-9_]+$/. finally @unlink.
- pruneOld: retentionDays<=0 → ничего. Удаляет только status=success старше now()-days по created_at, из S3 и из таблицы.
- Все Process: setTimeout(3600) (const PROCESS_TIMEOUT).

**How to apply:** RunBackupJob должен сам после успеха мутировать last_run_at и звать pruneOld(db, config->retention_days). Контроллер/Job зовут сервис, не Process напрямую.
