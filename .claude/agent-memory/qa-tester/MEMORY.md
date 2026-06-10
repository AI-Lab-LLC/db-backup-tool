# QA-Tester Memory Index — Backup Panel

- [Test setup](project_test_setup.md) — phpunit uses sqlite :memory: by default; pg binaries absent (audit code, don't execute backup/restore); Eloquent unit tests must extend Tests\TestCase.
- [Known quirks](project_known_quirks.md) — stock ExampleTest always fails (false positive: / redirects to login); AdminUserSeeder env() vs config:cache caveat.
