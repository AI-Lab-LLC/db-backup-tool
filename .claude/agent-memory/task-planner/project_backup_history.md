---
name: project-backup-history
description: Структура «Истории бэкапов» в панели — контроллер, вьюха, модель Backup, пагинация
metadata:
  type: project
---

«История бэкапов» — БЛОК 4 в `resources/views/backups/index.blade.php` (таблица + пагинация `$backups->links()` внизу).

Данные приходят из `App\Http\Controllers\BackupController@index` тремя переменными: `$configs` (коллекция BackupConfig), `$backups` (LengthAwarePaginator моделей Backup, `->latest()->paginate(25)`), `$discovered` (массив строк-имён баз с сервера).

Модель `App\Models\Backup` поля: database_name, filename, s3_path, size_bytes, status, trigger, error, started_at, finished_at + created_at/updated_at. Скоупов НЕТ. `created_at` всегда заполнен; `started_at`/`finished_at` могут быть null. В таблице истории колонка «Создан» показывает `created_at`, «Завершён» — `finished_at`.

**Why:** база для планирования фильтров/изменений в списке бэкапов.
**How to apply:** при добавлении фильтрации список собирается в `index()` через query builder на Backup; пагинацию нужно сохранять через `->appends(...)`. Каждый бэкап рендерится отдельным `<tbody>` c Alpine `x-data` (showRestore/confirmed) — фильтр-форму ставить НАД таблицей, вне `<table>`.
