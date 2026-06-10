---
name: auth-breeze
description: Auth layer (Breeze blade) — registration disabled, AdminUserSeeder from env, post-login redirect to /backups via dashboard route
metadata:
  type: project
---

Z5 auth task is done. Laravel Breeze (blade stack) is installed.

Key non-obvious decisions:
- **Registration is fully removed**, not just hidden: GET/POST `register` routes and the `RegisteredUserController` import deleted from `routes/auth.php`; `app/Http/Controllers/Auth/RegisteredUserController.php`, `resources/views/auth/register.blade.php`, and `tests/Feature/Auth/RegistrationTest.php` deleted. Only `Route::has('register')` guard / a comment remain in `welcome.blade.php`.
- **Post-login redirect to /backups**: Breeze (L13) has NO `RouteServiceProvider::HOME` — auth controllers redirect to `route('dashboard')` directly. Rather than touch every Auth controller, the `dashboard` named route in `routes/web.php` was repointed to `redirect('/backups')`. Root `/` redirects to `/backups` if authed else `route('login')`. `/backups` itself is owned by the other agent (BackupController) — referenced as a plain string so nothing breaks before it exists.
- **AdminUserSeeder** (`database/seeders/AdminUserSeeder.php`): `updateOrCreate` keyed on email, reads `ADMIN_EMAIL`/`ADMIN_PASSWORD` (and optional `ADMIN_NAME`, default 'Admin') via `env()`, password via `Hash::make`, sets `email_verified_at = now()`. Skips with a warning if either env var is blank. Registered in `DatabaseSeeder` via `$this->call(...)`; the old Test User factory call was removed.

**Why:** internal/dangerous tool — public signup must be impossible; accounts seeded from .env only (spec §8b).

**How to apply:** when the BackupController/`/backups` route lands, the string redirects already work. If you later add a real `dashboard`, note its name is currently the indirection to /backups. User model has `password => hashed` cast, so Hash::make is belt-and-suspenders (Laravel won't double-hash an existing bcrypt string). Verified by seeding a temp sqlite ([[project-bootstrap]] sqlite workaround) — real .env stays pgsql.
