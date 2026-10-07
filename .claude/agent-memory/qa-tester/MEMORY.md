# QA-Tester Memory Index — Backup Panel

- [Test setup](project_test_setup.md) — phpunit uses sqlite :memory: by default; pg binaries absent (audit code, don't execute backup/restore); Eloquent unit tests must extend Tests\TestCase.
- [Known quirks](project_known_quirks.md) — retention/prune data-loss hotspot; tests use array cache (locks untested); delete on running rows.
