<?php if (isset($component)) { $__componentOriginal9ac128a9029c0e4701924bd2d73d7f54 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54 = $attributes; } ?>
<?php $component = App\View\Components\AppLayout::resolve([] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('app-layout'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\AppLayout::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
     <?php $__env->slot('header', null, []); ?> 
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Управление бэкапами PostgreSQL
        </h2>
     <?php $__env->endSlot(); ?>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">

            

            <?php if(session('status')): ?>
                <div class="rounded-md bg-green-50 border border-green-300 p-4">
                    <div class="flex items-start gap-3">
                        <svg class="h-5 w-5 text-green-500 mt-0.5 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        <p class="text-sm text-green-800 font-medium"><?php echo e(session('status')); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if(session('error')): ?>
                <div class="rounded-md bg-red-50 border border-red-300 p-4">
                    <div class="flex items-start gap-3">
                        <svg class="h-5 w-5 text-red-500 mt-0.5 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-1-9v4a1 1 0 102 0V9a1 1 0 10-2 0zm0-4a1 1 0 112 0 1 1 0 01-2 0z" clip-rule="evenodd"/>
                        </svg>
                        <p class="text-sm text-red-800 font-medium"><?php echo e(session('error')); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if(session('warning')): ?>
                <div class="rounded-md bg-yellow-50 border border-yellow-300 p-4">
                    <div class="flex items-start gap-3">
                        <svg class="h-5 w-5 text-yellow-500 mt-0.5 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        <p class="text-sm text-yellow-800 font-medium"><?php echo e(session('warning')); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if($errors->any()): ?>
                <div class="rounded-md bg-red-50 border border-red-300 p-4">
                    <div class="flex items-start gap-3">
                        <svg class="h-5 w-5 text-red-500 mt-0.5 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-1-9v4a1 1 0 102 0V9a1 1 0 10-2 0zm0-4a1 1 0 112 0 1 1 0 01-2 0z" clip-rule="evenodd"/>
                        </svg>
                        <div>
                            <p class="text-sm font-semibold text-red-800 mb-1">Ошибки валидации:</p>
                            <ul class="list-disc list-inside space-y-0.5">
                                <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <li class="text-sm text-red-700"><?php echo e($error); ?></li>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Базы данных</h3>
                    <p class="text-sm text-gray-500 mt-0.5">Интервал автобэкапа, срок хранения и статус для каждой базы</p>
                </div>

                <?php if($configs->isEmpty()): ?>
                    <div class="p-6 text-center text-sm text-gray-400">
                        Нет настроенных баз данных. Добавьте базы из раздела «Обнаруженные базы» ниже.
                    </div>
                <?php else: ?>
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
                                <?php $__currentLoopData = $configs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $config): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <tr class="hover:bg-gray-50 align-middle">
                                        
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="font-mono font-semibold text-gray-900 text-sm"><?php echo e($config->database_name); ?></span>
                                        </td>

                                        
                                        <form action="<?php echo e(route('backups.config')); ?>" method="POST" class="contents">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="database_name" value="<?php echo e($config->database_name); ?>">

                                            <td class="px-4 py-2">
                                                <?php if (isset($component)) { $__componentOriginal18c21970322f9e5c938bc954620c12bb = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal18c21970322f9e5c938bc954620c12bb = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.text-input','data' => ['type' => 'text','name' => 'label','value' => ''.e(old('label', $config->label)).'','placeholder' => 'Название','class' => 'w-36 text-sm py-1.5 px-2']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('text-input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'text','name' => 'label','value' => ''.e(old('label', $config->label)).'','placeholder' => 'Название','class' => 'w-36 text-sm py-1.5 px-2']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal18c21970322f9e5c938bc954620c12bb)): ?>
<?php $attributes = $__attributesOriginal18c21970322f9e5c938bc954620c12bb; ?>
<?php unset($__attributesOriginal18c21970322f9e5c938bc954620c12bb); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal18c21970322f9e5c938bc954620c12bb)): ?>
<?php $component = $__componentOriginal18c21970322f9e5c938bc954620c12bb; ?>
<?php unset($__componentOriginal18c21970322f9e5c938bc954620c12bb); ?>
<?php endif; ?>
                                            </td>

                                            <td class="px-4 py-2">
                                                <div class="flex items-center gap-1.5">
                                                    <?php if (isset($component)) { $__componentOriginal18c21970322f9e5c938bc954620c12bb = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal18c21970322f9e5c938bc954620c12bb = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.text-input','data' => ['type' => 'number','name' => 'interval_minutes','value' => ''.e(old('interval_minutes', $config->interval_minutes)).'','min' => '0','class' => 'w-20 text-sm py-1.5 px-2']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('text-input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'number','name' => 'interval_minutes','value' => ''.e(old('interval_minutes', $config->interval_minutes)).'','min' => '0','class' => 'w-20 text-sm py-1.5 px-2']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal18c21970322f9e5c938bc954620c12bb)): ?>
<?php $attributes = $__attributesOriginal18c21970322f9e5c938bc954620c12bb; ?>
<?php unset($__attributesOriginal18c21970322f9e5c938bc954620c12bb); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal18c21970322f9e5c938bc954620c12bb)): ?>
<?php $component = $__componentOriginal18c21970322f9e5c938bc954620c12bb; ?>
<?php unset($__componentOriginal18c21970322f9e5c938bc954620c12bb); ?>
<?php endif; ?>
                                                    <span class="text-gray-400 text-xs">0 = вручную</span>
                                                </div>
                                            </td>

                                            <td class="px-4 py-2">
                                                <?php if (isset($component)) { $__componentOriginal18c21970322f9e5c938bc954620c12bb = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal18c21970322f9e5c938bc954620c12bb = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.text-input','data' => ['type' => 'number','name' => 'retention_days','value' => ''.e(old('retention_days', $config->retention_days)).'','min' => '0','class' => 'w-20 text-sm py-1.5 px-2']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('text-input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'number','name' => 'retention_days','value' => ''.e(old('retention_days', $config->retention_days)).'','min' => '0','class' => 'w-20 text-sm py-1.5 px-2']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal18c21970322f9e5c938bc954620c12bb)): ?>
<?php $attributes = $__attributesOriginal18c21970322f9e5c938bc954620c12bb; ?>
<?php unset($__attributesOriginal18c21970322f9e5c938bc954620c12bb); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal18c21970322f9e5c938bc954620c12bb)): ?>
<?php $component = $__componentOriginal18c21970322f9e5c938bc954620c12bb; ?>
<?php unset($__componentOriginal18c21970322f9e5c938bc954620c12bb); ?>
<?php endif; ?>
                                            </td>

                                            <td class="px-4 py-2 text-center">
                                                <input
                                                    type="checkbox"
                                                    name="enabled"
                                                    value="1"
                                                    <?php echo e(old('enabled', $config->enabled) ? 'checked' : ''); ?>

                                                    class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                                >
                                            </td>

                                            <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">
                                                <?php echo e($config->last_run_at ? $config->last_run_at->format('d.m.Y H:i') : '—'); ?>

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

                                        
                                        <td class="px-4 py-2">
                                            <form action="<?php echo e(route('backups.run')); ?>" method="POST">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="database" value="<?php echo e($config->database_name); ?>">
                                                <button
                                                    type="submit"
                                                    class="inline-flex items-center px-3 py-1.5 bg-emerald-600 border border-transparent rounded-md text-xs font-semibold text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1 transition ease-in-out duration-150"
                                                >
                                                    Бэкап сейчас
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            
            <?php if(!empty($discovered)): ?>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Обнаруженные базы</h3>
                        <p class="text-sm text-gray-500 mt-0.5">Базы PostgreSQL на сервере, ещё не добавленные в мониторинг</p>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php $__currentLoopData = $discovered; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $dbName): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <div class="border border-gray-200 rounded-lg p-4 bg-gray-50 flex flex-col gap-3">
                                    <p class="font-mono font-semibold text-gray-800"><?php echo e($dbName); ?></p>
                                    <form action="<?php echo e(route('backups.config')); ?>" method="POST">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="database_name" value="<?php echo e($dbName); ?>">
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
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">История бэкапов</h3>
                    <p class="text-sm text-gray-500 mt-0.5">Журнал всех бэкапов с кнопками действий</p>
                </div>

                <?php if($backups->isEmpty()): ?>
                    <div class="p-6 text-center text-sm text-gray-400">
                        Бэкапов пока нет. Нажмите «Бэкап сейчас» для запуска первого бэкапа.
                    </div>
                <?php else: ?>
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

                            
                            <?php $__currentLoopData = $backups; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $backup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <?php
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
                                ?>

                                
                                <tbody
                                    x-data="{ showRestore: false, confirmed: false }"
                                    class="divide-y divide-gray-100"
                                >
                                    
                                    <tr class="bg-white hover:bg-gray-50">
                                        <td class="px-4 py-3 font-mono text-gray-900 whitespace-nowrap text-sm">
                                            <?php echo e($backup->database_name); ?>

                                        </td>
                                        <td class="px-4 py-3 max-w-xs">
                                            <span class="block truncate font-mono text-xs text-gray-600" title="<?php echo e($backup->filename); ?>">
                                                <?php echo e($backup->filename); ?>

                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap"><?php echo e($sizeStr); ?></td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo e($statusClass); ?>">
                                                <?php echo e($statusLabel); ?>

                                            </span>
                                            <?php if($backup->error): ?>
                                                <p class="mt-0.5 text-xs text-red-500 truncate max-w-xs" title="<?php echo e($backup->error); ?>">
                                                    <?php echo e(Str::limit($backup->error, 80)); ?>

                                                </p>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap text-xs">
                                            <?php echo e($backup->trigger === 'manual' ? 'Вручную' : 'По расписанию'); ?>

                                        </td>
                                        <td class="px-4 py-3 text-gray-500 text-xs whitespace-nowrap">
                                            <?php echo e($backup->created_at->format('d.m.Y H:i')); ?>

                                        </td>
                                        <td class="px-4 py-3 text-gray-500 text-xs whitespace-nowrap">
                                            <?php echo e($backup->finished_at ? $backup->finished_at->format('d.m.Y H:i') : '—'); ?>

                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex flex-col gap-1.5 min-w-max">

                                                
                                                <?php if($backup->status === 'success'): ?>
                                                    <a
                                                        href="<?php echo e(route('backups.download', $backup)); ?>"
                                                        class="inline-flex items-center justify-center px-3 py-1 bg-blue-50 border border-blue-300 rounded text-xs font-medium text-blue-700 hover:bg-blue-100 transition duration-150"
                                                    >
                                                        Скачать
                                                    </a>
                                                <?php endif; ?>

                                                
                                                <?php if($backup->status === 'success'): ?>
                                                    <button
                                                        type="button"
                                                        @click="showRestore = !showRestore; if (!showRestore) confirmed = false"
                                                        class="inline-flex items-center justify-center px-3 py-1 bg-orange-50 border border-orange-300 rounded text-xs font-medium text-orange-700 hover:bg-orange-100 transition duration-150"
                                                    >
                                                        <span x-text="showRestore ? 'Скрыть' : 'Restore'">Restore</span>
                                                    </button>
                                                <?php endif; ?>

                                                
                                                <form
                                                    action="<?php echo e(route('backups.destroy', $backup)); ?>"
                                                    method="POST"
                                                    onsubmit="return confirm('Удалить бэкап из S3 и истории? Это действие необратимо.')"
                                                >
                                                    <?php echo csrf_field(); ?>
                                                    <?php echo method_field('DELETE'); ?>
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

                                    
                                    <?php if($backup->status === 'success'): ?>
                                        <tr x-show="showRestore" x-cloak class="bg-red-50">
                                            <td colspan="8" class="px-6 py-5">

                                                
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

                                                
                                                <div class="text-xs text-gray-600 mb-4 space-y-1 bg-white rounded border border-gray-200 p-3">
                                                    <p>Файл дампа: <span class="font-mono text-gray-800"><?php echo e($backup->filename); ?></span></p>
                                                    <p>Исходная база: <span class="font-mono text-gray-800"><?php echo e($backup->database_name); ?></span></p>
                                                    <p>Создан: <span class="font-mono text-gray-800"><?php echo e($backup->created_at->format('d.m.Y H:i:s')); ?></span></p>
                                                </div>

                                                
                                                <form action="<?php echo e(route('backups.restore', $backup)); ?>" method="POST" class="space-y-4 max-w-lg">
                                                    <?php echo csrf_field(); ?>

                                                    
                                                    <div>
                                                        <label
                                                            for="target_database_<?php echo e($backup->id); ?>"
                                                            class="block text-sm font-medium text-gray-700 mb-1"
                                                        >
                                                            Целевая база данных
                                                            <span class="text-red-500 font-bold">*</span>
                                                        </label>
                                                        <?php if (isset($component)) { $__componentOriginal18c21970322f9e5c938bc954620c12bb = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal18c21970322f9e5c938bc954620c12bb = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.text-input','data' => ['type' => 'text','id' => 'target_database_'.e($backup->id).'','name' => 'target_database','value' => ''.e($backup->database_name).'','pattern' => '[A-Za-z0-9_]+','required' => true,'class' => 'w-full']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('text-input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'text','id' => 'target_database_'.e($backup->id).'','name' => 'target_database','value' => ''.e($backup->database_name).'','pattern' => '[A-Za-z0-9_]+','required' => true,'class' => 'w-full']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal18c21970322f9e5c938bc954620c12bb)): ?>
<?php $attributes = $__attributesOriginal18c21970322f9e5c938bc954620c12bb; ?>
<?php unset($__attributesOriginal18c21970322f9e5c938bc954620c12bb); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal18c21970322f9e5c938bc954620c12bb)): ?>
<?php $component = $__componentOriginal18c21970322f9e5c938bc954620c12bb; ?>
<?php unset($__componentOriginal18c21970322f9e5c938bc954620c12bb); ?>
<?php endif; ?>
                                                        <p class="mt-1 text-xs text-gray-400">
                                                            Только латинские буквы, цифры и символ подчёркивания
                                                        </p>
                                                    </div>

                                                    
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
                                                                <strong class="font-mono text-red-700"><?php echo e($backup->database_name); ?></strong>,
                                                                и подтверждаю выполнение операции.
                                                            </span>
                                                        </label>
                                                    </div>

                                                    
                                                    <div class="flex items-center gap-3">
                                                        
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
                                    <?php endif; ?>
                                </tbody>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

                        </table>
                    </div>

                    
                    <div class="px-6 py-4 border-t border-gray-200">
                        <?php echo e($backups->links()); ?>

                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54)): ?>
<?php $attributes = $__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54; ?>
<?php unset($__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9ac128a9029c0e4701924bd2d73d7f54)): ?>
<?php $component = $__componentOriginal9ac128a9029c0e4701924bd2d73d7f54; ?>
<?php unset($__componentOriginal9ac128a9029c0e4701924bd2d73d7f54); ?>
<?php endif; ?>
<?php /**PATH /home/it-user/workspace/projects/backup_panel/resources/views/backups/index.blade.php ENDPATH**/ ?>