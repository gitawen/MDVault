<?php

use App\Http\Controllers\EncryptedVaultController;
use App\Http\Controllers\ExistingVaultController;
use App\Http\Controllers\VaultController;
use App\Http\Controllers\VaultEncryptionController;
use App\Http\Controllers\VaultLockController;
use App\Http\Controllers\VaultPasswordController;
use App\Http\Controllers\VaultUnlockController;
use Illuminate\Support\Facades\Route;

Route::get('vaults', [VaultController::class, 'index'])->name('vaults.index');
Route::post('vaults', [VaultController::class, 'store'])->name('vaults.store');
Route::post('vaults/close', [VaultController::class, 'close'])->name('vaults.close');
Route::post('vaults/existing', [ExistingVaultController::class, 'store'])->name('vaults.existing.store');
Route::post('vaults/existing/browse', [ExistingVaultController::class, 'browse'])->name('vaults.existing.browse');

Route::patch('vaults/{vault:uuid}', [VaultController::class, 'update'])->whereUuid('vault')->name('vaults.update');
Route::delete('vaults/{vault:uuid}', [VaultController::class, 'destroy'])->whereUuid('vault')->name('vaults.destroy');
Route::post('vaults/{vault:uuid}/open', [VaultController::class, 'open'])->whereUuid('vault')->name('vaults.open');

Route::post('vaults/encrypted', [EncryptedVaultController::class, 'store'])->name('vaults.encrypted.store');
Route::post('vaults/lock', [VaultLockController::class, 'storeAll'])->name('vaults.lock.all');
Route::post('vaults/{vault:uuid}/unlock', [VaultUnlockController::class, 'store'])->whereUuid('vault')->middleware('throttle:10,1')->name('vaults.unlock');
Route::post('vaults/{vault:uuid}/lock', [VaultLockController::class, 'store'])->whereUuid('vault')->name('vaults.lock');
Route::post('vaults/{vault:uuid}/encryption', [VaultEncryptionController::class, 'store'])->whereUuid('vault')->name('vaults.encryption.store');
Route::delete('vaults/{vault:uuid}/encryption', [VaultEncryptionController::class, 'destroy'])->whereUuid('vault')->middleware('throttle:10,1')->name('vaults.encryption.destroy');
Route::put('vaults/{vault:uuid}/encryption/password', [VaultPasswordController::class, 'update'])->whereUuid('vault')->middleware('throttle:10,1')->name('vaults.encryption.password.update');
