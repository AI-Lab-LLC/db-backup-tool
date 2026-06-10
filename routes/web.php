<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// Root: authenticated users go to the panel, guests to the login screen.
// (The /backups route is provided separately by BackupController; referenced
//  here as a string so this file does not break before that route exists.)
Route::get('/', function () {
    return redirect(auth()->check() ? '/backups' : route('login'));
});

// Breeze references route('dashboard') as its post-auth landing target.
// We keep the name but send it to the panel instead of the default dashboard.
Route::get('/dashboard', function () {
    return redirect('/backups');
})->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Backup panel.
    Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
    Route::post('/backups/config', [BackupController::class, 'saveConfig'])->name('backups.config');
    Route::post('/backups/run', [BackupController::class, 'runNow'])->name('backups.run');
    Route::get('/backups/{backup}/download', [BackupController::class, 'download'])->name('backups.download');
    Route::post('/backups/{backup}/restore', [BackupController::class, 'restore'])->name('backups.restore');
    Route::delete('/backups/{backup}', [BackupController::class, 'destroy'])->name('backups.destroy');
});

require __DIR__.'/auth.php';
