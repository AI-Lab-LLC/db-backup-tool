---
name: project-known-quirks
description: Known false-positive test failure and seeder env() caveat in backup_panel
metadata:
  type: project
---

Backup Panel known quirks (verified 2026-06-05, Z11 QA):

- **Stock `tests/Feature/ExampleTest.php` always FAILS** — it asserts `GET /` returns 200, but `/` redirects guests to /login (302) per spec §8b. This is the default Breeze test, not a product bug. The full suite shows "37 passed, 1 failed" because of this. Don't treat it as a regression; flag it for cleanup (rewrite as assertRedirect(route('login')) or delete).
- **`AdminUserSeeder` reads `env('ADMIN_EMAIL')` / `env('ADMIN_PASSWORD')` directly** — under `config:cache` `env()` outside config files returns null, so the seeder would skip. README instructs seeding before caching, so LOW severity. Don't re-file as a fresh bug each cycle.

**Why:** these two recur every QA pass and look like failures but are known/accepted.
**How to apply:** when triaging, subtract these from any FAIL count before deciding the build is broken.
