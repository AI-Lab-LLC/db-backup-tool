---
name: restore-queue-locks
description: Restore runs via RunRestoreJob + restores table; cross-job exclusion scheme (backup unique lock + pg-db-busy lock); timeout budget 5400/5600/5700/150min
metadata:
  type: project
---

Since 2026-10-07 restore is queued: controller creates `Restore` row (queued) → `RunRestoreJob::dispatchIfIdle`. `RunRestoreJob` tries=1 (destructive, never retry), failOnTimeout.

Cross-job exclusion (UniqueLock keys are class-scoped, so ShouldBeUnique alone doesn't separate backup vs restore):
- RunRestoreJob takes RunBackupJob's UniqueLock for the target for the whole restore → backup can't be queued meanwhile; if held → restore fails "backup ... in progress or queued".
- `App\Jobs\DatabaseBusyLock` (`pg-db-busy:{db}`, TTL 5600) taken in both handle()s. RunBackupJob on conflict returns early WITHOUT a row (no backoff, no tries burned).
- DB checks: dispatch/runNow skip if `Restore::activeFor(db)`; restore path rejects if Backup running / `RunBackupJob::isQueuedOrRunning`.
- `BackupService::markStaleRestores()` (called by backups:dispatch + backups:prune) — otherwise a dead restore row blocks backups forever.

Timeout budget (guarded by tests): job timeout 5400 < uniqueFor 5600 < retry_after 5700 < STALE_RUNNING_MINUTES 150*60. PROCESS_TIMEOUT stays 3600.

**Why:** user-specified hardening batch A–G. **How to apply:** any new job touching a DB must take DatabaseBusyLock; mocks of BackupService in command tests must expect markStaleRestores. Trusted proxies live in config/trustedproxy.php (env TRUSTED_PROXIES), not bootstrap env() (config:cache).
