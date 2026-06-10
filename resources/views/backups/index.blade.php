<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Управление бэкапами PostgreSQL
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">

            {{-- ================================================================
                 БЛОК 1: Флеш-сообщения и ошибки валидации
            ================================================================ --}}

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-300 p-4">
                    <div class="flex items-start gap-3">
                        <svg class="h-5 w-5 text-green-500 mt-0.5 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        <p class="text-sm text-green-800 font-medium">{{ session('status') }}</p>
                    </div>
                </div>
            @endif

            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-300 p-4">
                    <div class="flex items-start gap-3">
                        <svg class="h-5 w-5 text-red-500 mt-0.5 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-1-9v4a1 1 0 102 0V9a1 1 0 10-2 0zm0-4a1 1 0 112 0 1 1 0 01-2 0z" clip-rule="evenodd"/>
                        </svg>
                        <p class="text-sm text-red-800 font-medium">{{ session('error') }}</p>
                    </div>
                </div>
            @endif

            @if (session('warning'))
                <div class="rounded-md bg-yellow-50 border border-yellow-300 p-4">
                    <div class="flex items-start gap-3">
                        <svg class="h-5 w-5 text-yellow-500 mt-0.5 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        <p class="text-sm text-yellow-800 font-medium">{{ session('warning') }}</p>
                    </div>
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-md bg-red-50 border border-red-300 p-4">
                    <div class="flex items-start gap-3">
                        <svg class="h-5 w-5 text-red-500 mt-0.5 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-1-9v4a1 1 0 102 0V9a1 1 0 10-2 0zm0-4a1 1 0 112 0 1 1 0 01-2 0z" clip-rule="evenodd"/>
                        </svg>
                        <div>
                            <p class="text-sm font-semibold text-red-800 mb-1">Ошибки валидации:</p>
                            <ul class="list-disc list-inside space-y-0.5">
                                @foreach ($errors->all() as $error)
                                    <li class="text-sm text-red-700">{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ================================================================
                 БЛОК 2: Настройки баз данных
            ================================================================ --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Базы данных</h3>
                    <p class="text-sm text-gray-500 mt-0.5">Интервал автобэкапа, срок хранения и статус для каждой базы</p>
                </div>

                @if ($configs->isEmpty())
                    <div class="p-6 text-center text-sm text-gray-400">
                        Нет настроенных баз данных. Добавьте базы из раздела «Обнаруженные базы» ниже.
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">База данных</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Метка</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Интервал (мин)</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Хранение (дней)</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Вкл</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Последний бэкап</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Сохранить</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Запуск</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                @foreach ($configs as $config)
                                    <tr class="hover:bg-gray-50 align-middle">
                                        {{-- Имя базы (отображение) --}}
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="font-mono font-semibold text-gray-900 text-sm">{{ $config->database_name }}</span>
                                        </td>

                                        {{-- Inline-форма конфига — использует form.contents чтобы td были дочерними --}}
                                        <form action="{{ route('backups.config') }}" method="POST" class="contents">
                                            @csrf
                                            <input type="hidden" name="database_name" value="{{ $config->database_name }}">

                                            <td class="px-4 py-2">
                                                <x-text-input
                                                    type="text"
                                                    name="label"
                                                    value="{{ old('label', $config->label) }}"
                                                    placeholder="Название"
                                                    class="w-36 text-sm py-1.5 px-2"
                                                />
                                            </td>

                                            <td class="px-4 py-2">
                                                <div class="flex items-center gap-1.5">
                                                    <x-text-input
                                                        type="number"
                                                        name="interval_minutes"
                                                        value="{{ old('interval_minutes', $config->interval_minutes) }}"
                                                        min="0"
                                                        class="w-20 text-sm py-1.5 px-2"
                                                    />
                                                    <span class="text-gray-400 text-xs">0 = вручную</span>
                                                </div>
                                            </td>

                                            <td class="px-4 py-2">
                                                <x-text-input
                                                    type="number"
                                                    name="retention_days"
                                                    value="{{ old('retention_days', $config->retention_days) }}"
                                                    min="0"
                                                    class="w-20 text-sm py-1.5 px-2"
                                                />
                                            </td>

                                            <td class="px-4 py-2 text-center">
                                                <input
                                                    type="checkbox"
                                                    name="enabled"
                                                    value="1"
                                                    {{ old('enabled', $config->enabled) ? 'checked' : '' }}
                                                    class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                                >
                                            </td>

                                            <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">
                                                {{ $config->last_run_at ? $config->last_run_at->format('d.m.Y H:i') : '—' }}
                                            </td>

                                            <td class="px-4 py-2">
                                                <button
                                                    type="submit"
                                                    class="inline-flex items-center px-3 py-1.5 bg-indigo-600 border border-transparent rounded-md text-xs font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1 transition ease-in-out duration-150"
                                                >
                                                    Сохранить
                                                </button>
                                            </td>
                                        </form>

                                        {{-- Кнопка «Бэкап сейчас» — отдельная форма в отдельной ячейке --}}
                                        <td class="px-4 py-2">
                                            <form action="{{ route('backups.run') }}" method="POST">
                                                @csrf
                                                <input type="hidden" name="database" value="{{ $config->database_name }}">
                                                <button
                                                    type="submit"
                                                    class="inline-flex items-center px-3 py-1.5 bg-emerald-600 border border-transparent rounded-md text-xs font-semibold text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1 transition ease-in-out duration-150"
                                                >
                                                    Бэкап сейчас
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ================================================================
                 БЛОК 3: Обнаруженные базы (не добавленные в мониторинг)
            ================================================================ --}}
            @if (!empty($discovered))
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Обнаруженные базы</h3>
                        <p class="text-sm text-gray-500 mt-0.5">Базы PostgreSQL на сервере, ещё не добавленные в мониторинг</p>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            @foreach ($discovered as $dbName)
                                <div class="border border-gray-200 rounded-lg p-4 bg-gray-50 flex flex-col gap-3">
                                    <p class="font-mono font-semibold text-gray-800">{{ $dbName }}</p>
                                    <form action="{{ route('backups.config') }}" method="POST">
                                        @csrf
                                        <input type="hidden" name="database_name" value="{{ $dbName }}">
                                        <input type="hidden" name="interval_minutes" value="60">
                                        <input type="hidden" name="retention_days" value="7">
                                        <input type="hidden" name="enabled" value="1">
                                        <button
                                            type="submit"
                                            class="w-full inline-flex justify-center items-center px-3 py-2 bg-indigo-50 border border-indigo-300 rounded-md text-xs font-semibold text-indigo-700 hover:bg-indigo-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1 transition ease-in-out duration-150"
                                        >
                                            + Добавить в мониторинг
                                        </button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            {{-- ================================================================
                 БЛОК 4: История бэкапов
                 Alpine x-data задан на <tbody> для каждой пары строк
            ================================================================ --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">История бэкапов</h3>
                    <p class="text-sm text-gray-500 mt-0.5">Журнал всех бэкапов с кнопками действий</p>
                </div>

                {{-- ---- Фильтры ---- --}}
                <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
                    <form method="GET" action="{{ route('backups.index') }}" class="flex flex-wrap items-end gap-4">

                        {{-- База данных --}}
                        <div class="flex flex-col gap-1 min-w-[160px]">
                            <x-input-label for="filter_database" value="База данных" />
                            <select
                                id="filter_database"
                                name="database"
                                class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                            >
                                <option value="">Все базы</option>
                                @foreach ($databaseOptions as $dbOpt)
                                    <option value="{{ $dbOpt }}" @selected($dbOpt === $filterDatabase)>
                                        {{ $dbOpt }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Дата с --}}
                        <div class="flex flex-col gap-1">
                            <x-input-label for="filter_date_from" value="С" />
                            <x-text-input
                                id="filter_date_from"
                                type="date"
                                name="date_from"
                                value="{{ $filterDateFrom }}"
                                class="text-sm"
                            />
                        </div>

                        {{-- Дата по --}}
                        <div class="flex flex-col gap-1">
                            <x-input-label for="filter_date_to" value="По" />
                            <x-text-input
                                id="filter_date_to"
                                type="date"
                                name="date_to"
                                value="{{ $filterDateTo }}"
                                class="text-sm"
                            />
                        </div>

                        {{-- Кнопки --}}
                        <div class="flex items-end gap-3 pb-0.5">
                            <button
                                type="submit"
                                class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1 transition ease-in-out duration-150"
                            >
                                Применить
                            </button>

                            @if ($filterDatabase || $filterDateFrom || $filterDateTo)
                                <a
                                    href="{{ route('backups.index') }}"
                                    class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1 transition ease-in-out duration-150"
                                >
                                    Сбросить
                                </a>
                            @endif
                        </div>

                    </form>
                </div>
                {{-- ---- /Фильтры ---- --}}

                @if ($backups->isEmpty())
                    <div class="p-6 text-center text-sm text-gray-400">
                        @if ($filterDatabase || $filterDateFrom || $filterDateTo)
                            Под выбранные фильтры бэкапов не найдено.
                        @else
                            Бэкапов пока нет. Нажмите «Бэкап сейчас» для запуска первого бэкапа.
                        @endif
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">База</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Файл</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Размер</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Статус</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Тип</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Создан</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Завершён</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Действия</th>
                                </tr>
                            </thead>

                            {{-- Каждый бэкап — отдельный <tbody> с Alpine x-data, чтобы
                                 основная строка и строка Restore разделяли одно состояние --}}
                            @foreach ($backups as $backup)
                                @php
                                    // Форматирование размера файла
                                    $bytes = $backup->size_bytes;
                                    if ($bytes === null) {
                                        $sizeStr = '—';
                                    } elseif ($bytes >= 1073741824) {
                                        $sizeStr = number_format($bytes / 1073741824, 2) . ' ГБ';
                                    } elseif ($bytes >= 1048576) {
                                        $sizeStr = number_format($bytes / 1048576, 2) . ' МБ';
                                    } elseif ($bytes >= 1024) {
                                        $sizeStr = number_format($bytes / 1024, 1) . ' КБ';
                                    } else {
                                        $sizeStr = $bytes . ' Б';
                                    }

                                    $statusClass = match($backup->status) {
                                        'success' => 'bg-green-100 text-green-800',
                                        'failed'  => 'bg-red-100 text-red-800',
                                        'running' => 'bg-yellow-100 text-yellow-800',
                                        default   => 'bg-gray-100 text-gray-600',
                                    };
                                    $statusLabel = match($backup->status) {
                                        'success' => 'Успех',
                                        'failed'  => 'Ошибка',
                                        'running' => 'Выполняется',
                                        default   => 'Ожидание',
                                    };
                                @endphp

                                {{-- tbody с Alpine x-data — scope для showRestore и confirmed --}}
                                <tbody
                                    x-data="{ showRestore: false, confirmed: false }"
                                    class="divide-y divide-gray-100"
                                >
                                    {{-- Основная строка бэкапа --}}
                                    <tr class="bg-white hover:bg-gray-50">
                                        <td class="px-4 py-3 font-mono text-gray-900 whitespace-nowrap text-sm">
                                            {{ $backup->database_name }}
                                        </td>
                                        <td class="px-4 py-3 max-w-xs">
                                            <span class="block truncate font-mono text-xs text-gray-600" title="{{ $backup->filename }}">
                                                {{ $backup->filename }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $sizeStr }}</td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $statusClass }}">
                                                {{ $statusLabel }}
                                            </span>
                                            @if ($backup->error)
                                                <p class="mt-0.5 text-xs text-red-500 truncate max-w-xs" title="{{ $backup->error }}">
                                                    {{ Str::limit($backup->error, 80) }}
                                                </p>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap text-xs">
                                            {{ $backup->trigger === 'manual' ? 'Вручную' : 'По расписанию' }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-500 text-xs whitespace-nowrap">
                                            {{ $backup->created_at->format('d.m.Y H:i') }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-500 text-xs whitespace-nowrap">
                                            {{ $backup->finished_at ? $backup->finished_at->format('d.m.Y H:i') : '—' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex flex-col gap-1.5 min-w-max">

                                                {{-- Скачать — только для успешных --}}
                                                @if ($backup->status === 'success')
                                                    <a
                                                        href="{{ route('backups.download', $backup) }}"
                                                        class="inline-flex items-center justify-center px-3 py-1 bg-blue-50 border border-blue-300 rounded text-xs font-medium text-blue-700 hover:bg-blue-100 transition duration-150"
                                                    >
                                                        Скачать
                                                    </a>
                                                @endif

                                                {{-- Restore — только для успешных --}}
                                                @if ($backup->status === 'success')
                                                    <button
                                                        type="button"
                                                        @click="showRestore = !showRestore; if (!showRestore) confirmed = false"
                                                        class="inline-flex items-center justify-center px-3 py-1 bg-orange-50 border border-orange-300 rounded text-xs font-medium text-orange-700 hover:bg-orange-100 transition duration-150"
                                                    >
                                                        <span x-text="showRestore ? 'Скрыть' : 'Restore'">Restore</span>
                                                    </button>
                                                @endif

                                                {{-- Удалить --}}
                                                <form
                                                    action="{{ route('backups.destroy', $backup) }}"
                                                    method="POST"
                                                    onsubmit="return confirm('Удалить бэкап из S3 и истории? Это действие необратимо.')"
                                                >
                                                    @csrf
                                                    @method('DELETE')
                                                    <button
                                                        type="submit"
                                                        class="w-full inline-flex items-center justify-center px-3 py-1 bg-red-50 border border-red-300 rounded text-xs font-medium text-red-700 hover:bg-red-100 transition duration-150"
                                                    >
                                                        Удалить
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>

                                    {{-- ================================================================
                                         Строка формы Restore — скрытая, управляется x-show="showRestore"
                                         x-data наследуется из родительского <tbody>
                                    ================================================================ --}}
                                    @if ($backup->status === 'success')
                                        <tr x-show="showRestore" x-cloak class="bg-red-50">
                                            <td colspan="8" class="px-6 py-5">

                                                {{-- ЯРКОЕ ПРЕДУПРЕЖДЕНИЕ --}}
                                                <div class="rounded-lg bg-red-100 border-2 border-red-500 p-4 mb-4">
                                                    <div class="flex items-start gap-3">
                                                        <svg class="h-6 w-6 text-red-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                                                        </svg>
                                                        <div>
                                                            <p class="font-bold text-red-800 text-sm uppercase tracking-wide">
                                                                ВНИМАНИЕ! Деструктивная операция — восстановление необратимо
                                                            </p>
                                                            <p class="text-sm text-red-700 mt-1 leading-relaxed">
                                                                Восстановление <strong>ПЕРЕЗАПИШЕТ И УНИЧТОЖИТ</strong> все существующие данные
                                                                в целевой базе данных. Команда выполняет
                                                                <code class="bg-red-200 px-1 rounded font-mono text-xs">pg_restore --clean --if-exists --no-owner</code>
                                                                — все таблицы и данные целевой базы будут удалены и заменены данными из дампа.
                                                            </p>
                                                            <p class="text-sm font-bold text-red-800 mt-2">
                                                                Убедитесь, что вы указываете правильную целевую базу данных!
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Информация о дампе --}}
                                                <div class="text-xs text-gray-600 mb-4 space-y-1 bg-white rounded border border-gray-200 p-3">
                                                    <p>Файл дампа: <span class="font-mono text-gray-800">{{ $backup->filename }}</span></p>
                                                    <p>Исходная база: <span class="font-mono text-gray-800">{{ $backup->database_name }}</span></p>
                                                    <p>Создан: <span class="font-mono text-gray-800">{{ $backup->created_at->format('d.m.Y H:i:s') }}</span></p>
                                                </div>

                                                {{-- Форма restore --}}
                                                <form action="{{ route('backups.restore', $backup) }}" method="POST" class="space-y-4 max-w-lg">
                                                    @csrf

                                                    {{-- Целевая база данных --}}
                                                    <div>
                                                        <label
                                                            for="target_database_{{ $backup->id }}"
                                                            class="block text-sm font-medium text-gray-700 mb-1"
                                                        >
                                                            Целевая база данных
                                                            <span class="text-red-500 font-bold">*</span>
                                                        </label>
                                                        <x-text-input
                                                            type="text"
                                                            id="target_database_{{ $backup->id }}"
                                                            name="target_database"
                                                            value="{{ $backup->database_name }}"
                                                            pattern="[A-Za-z0-9_]+"
                                                            required
                                                            class="w-full"
                                                        />
                                                        <p class="mt-1 text-xs text-gray-400">
                                                            Только латинские буквы, цифры и символ подчёркивания
                                                        </p>
                                                    </div>

                                                    {{-- Чекбокс подтверждения --}}
                                                    <div class="rounded-lg bg-white border-2 border-red-300 p-4">
                                                        <label class="flex items-start gap-3 cursor-pointer">
                                                            <input
                                                                type="checkbox"
                                                                name="confirm"
                                                                value="1"
                                                                x-model="confirmed"
                                                                class="mt-0.5 h-4 w-4 rounded border-gray-300 text-red-600 shadow-sm focus:ring-red-500"
                                                            >
                                                            <span class="text-sm text-gray-700 leading-snug">
                                                                Я понимаю, что восстановление
                                                                <strong class="text-red-700">необратимо уничтожит все текущие данные</strong>
                                                                в базе
                                                                <strong class="font-mono text-red-700">{{ $backup->database_name }}</strong>,
                                                                и подтверждаю выполнение операции.
                                                            </span>
                                                        </label>
                                                    </div>

                                                    {{-- Кнопки --}}
                                                    <div class="flex items-center gap-3">
                                                        {{-- Submit — заблокирован пока confirmed=false --}}
                                                        <button
                                                            type="submit"
                                                            :disabled="!confirmed"
                                                            :class="confirmed
                                                                ? 'bg-red-600 hover:bg-red-700 cursor-pointer'
                                                                : 'bg-red-300 cursor-not-allowed opacity-60 pointer-events-none'"
                                                            class="inline-flex items-center px-5 py-2 border border-transparent rounded-md text-sm font-semibold text-white transition ease-in-out duration-150 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
                                                        >
                                                            Восстановить базу данных
                                                        </button>
                                                        <button
                                                            type="button"
                                                            @click="showRestore = false; confirmed = false"
                                                            class="inline-flex items-center px-5 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 transition ease-in-out duration-150"
                                                        >
                                                            Отмена
                                                        </button>
                                                    </div>
                                                </form>

                                            </td>
                                        </tr>
                                    @endif
                                </tbody>
                            @endforeach

                        </table>
                    </div>

                    {{-- Пагинация --}}
                    <div class="px-6 py-4 border-t border-gray-200">
                        {{ $backups->links() }}
                    </div>
                @endif
            </div>

        </div>
    </div>

</x-app-layout>
