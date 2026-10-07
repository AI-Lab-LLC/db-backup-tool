---
name: project-known-quirks
description: Recurring review pitfalls in backup_panel — prune data-loss class, array cache in tests hides lock behaviour
metadata:
  type: project
---

Backup Panel review notes (updated 2026-10-07):

- Old quirks (ExampleTest failing, AdminUserSeeder env()) are resolved: full suite was 89/89 green on 2026-10-07 and seeder reads `config('backup.admin')`.
- **Retention is a data-loss hotspot.** Spec §9 ties pruning to a fresh success. Any prune path that runs independently of a fresh success (e.g. a daily `backups:prune` command) can wipe the last valid dump of a failing/disabled/manual-only DB. Always check "newest success is never pruned".
- **Tests use `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`.** ShouldBeUnique locks and schedule `withoutOverlapping` mutexes on the prod `database` cache store aren't exercised; reason about them from vendor code (Laravel 13: CallQueuedHandler releases unique lock on success and on final failed()).
- The history view shows "Delete" for every status, including `running`. Watch for in-flight-row deletion.

- **TrustProxies on-forge fallback:** Laravel's TrustProxies treats `trustedproxy.proxies === null` + host `*.on-forge.com`/`*.on-vapor.com`/Laravel Cloud as `'*'`. Prod host is `*.on-forge.com`, so a null default makes XFF spoofable (IP allowlist bypass). Tests on `localhost` don't catch it — probe with an on-forge Host.

**Why:** these came up as real bugs in the 2026-10-07 reliability review.
**How to apply:** check them first when reviewing backup/prune/delete changes.
