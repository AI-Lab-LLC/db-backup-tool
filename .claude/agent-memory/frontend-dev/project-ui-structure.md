---
name: project-ui-structure
description: Layout, компоненты Breeze и структура view backups/index для Backup Panel
metadata:
  type: project
---

## Layout и компоненты Breeze

- Layout: `<x-app-layout>` с `<x-slot name="header">` — файл `resources/views/layouts/app.blade.php`.
- Подключение ассетов: `@vite(['resources/css/app.css', 'resources/js/app.js'])` — Vite, не CDN.
- Alpine.js идёт вместе с Breeze через `resources/js/app.js` — импортировать отдельно не нужно.
- Компоненты: `<x-text-input>`, `<x-primary-button>`, `<x-input-label>`, `<x-input-error>` и др. в `resources/views/components/`.
- `[x-cloak] { display: none !important; }` добавлен в `resources/css/app.css`.

## Вьюха backups/index

Файл: `resources/views/backups/index.blade.php`

Переменные от контроллера:
- `$configs` — Collection BackupConfig, поля: id, database_name, label, enabled, interval_minutes, retention_days, last_run_at
- `$backups` — LengthAwarePaginator Backup, поля: id, database_name, filename, size_bytes, status, trigger, error, started_at, finished_at, created_at
- `$discovered` — array имён БД

Секции и роуты:
1. Флеш: session('status'), session('error'), session('warning') + $errors->any()
2. Настройки баз: таблица, inline-форма `route('backups.config')` POST с `database_name`, `label`, `interval_minutes`, `retention_days`, `enabled`; отдельная форма `route('backups.run')` POST с `database`
3. Обнаруженные базы: `$discovered` не пустой — grid карточек, форма `route('backups.config')` с дефолтами interval=60, retention=7, enabled=1
4. История: таблица, каждый бэкап в отдельном `<tbody x-data="{ showRestore: false, confirmed: false }">` — это ключевой паттерн для Alpine
5. Restore-форма: `<tr x-show="showRestore" x-cloak>` в том же tbody; POST `route('backups.restore', $backup)`; поля: `target_database`, `confirm`; кнопка submit `:disabled="!confirmed"` + `:class` условный; яркое предупреждение bg-red-100 border-red-500

Alpine паттерн для restore:
- `<tbody x-data="{ showRestore: false, confirmed: false }">` — scope для одного бэкапа
- Кнопка в основной строке: `@click="showRestore = !showRestore; if (!showRestore) confirmed = false"`
- Строка формы: `<tr x-show="showRestore" x-cloak>`
- Submit кнопка: `:disabled="!confirmed"` + `:class="confirmed ? 'bg-red-600 ...' : 'bg-red-300 cursor-not-allowed opacity-60 pointer-events-none'"`

Форматирование size_bytes через @php inline в foreach (ГБ/МБ/КБ/Б).

**Why:** Паттерн `<tbody x-data>` решает проблему разделения состояния между соседними `<tr>` — Alpine scope работает на уровне tbody, не tr.

**How to apply:** При необходимости expandable строк в таблице — всегда оборачивай группу строк в `<tbody x-data>`.
