<?php

use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\BackupController;
use App\Http\Controllers\Settings\BackupRestoreController;
use App\Http\Controllers\Settings\DatabaseResetController;
use App\Http\Controllers\Settings\EditorController;
use App\Http\Controllers\Settings\GeneralController;
use App\Http\Controllers\Settings\StorageController;
use Illuminate\Support\Facades\Route;

Route::redirect('settings', '/settings/general')->name('settings.index');

Route::get('settings/general', [GeneralController::class, 'edit'])->name('settings.general.edit');
Route::patch('settings/general', [GeneralController::class, 'update'])->name('settings.general.update');

Route::get('settings/storage', [StorageController::class, 'edit'])->name('settings.storage.edit');
Route::patch('settings/storage', [StorageController::class, 'update'])->name('settings.storage.update');
Route::post('settings/storage/browse', [StorageController::class, 'browse'])->name('settings.storage.browse');
Route::delete('settings/storage', [StorageController::class, 'destroy'])->name('settings.storage.destroy');

Route::get('settings/editor', [EditorController::class, 'edit'])->name('settings.editor.edit');
Route::patch('settings/editor', [EditorController::class, 'update'])->name('settings.editor.update');

Route::get('settings/appearance', [AppearanceController::class, 'edit'])->name('settings.appearance.edit');
Route::patch('settings/appearance', [AppearanceController::class, 'update'])->name('settings.appearance.update');

Route::get('settings/backup', [BackupController::class, 'edit'])->name('settings.backup.edit');
Route::post('settings/backup', [BackupController::class, 'store'])->name('settings.backup.store');
Route::post('settings/backup/restore/browse', [BackupRestoreController::class, 'browse'])->name('settings.backup.restore.browse');
Route::post('settings/backup/restore/inspect', [BackupRestoreController::class, 'inspect'])->name('settings.backup.restore.inspect');
Route::post('settings/backup/restore', [BackupRestoreController::class, 'store'])->name('settings.backup.restore.store');
Route::delete('settings/backup/database', [DatabaseResetController::class, 'destroy'])->name('settings.backup.database.destroy');
