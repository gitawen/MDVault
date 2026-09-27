<?php

use App\Http\Controllers\ExistingVaultController;
use App\Http\Controllers\VaultController;
use Illuminate\Support\Facades\Route;

Route::get('vaults', [VaultController::class, 'index'])->name('vaults.index');
Route::post('vaults', [VaultController::class, 'store'])->name('vaults.store');
Route::post('vaults/close', [VaultController::class, 'close'])->name('vaults.close');
Route::post('vaults/existing', [ExistingVaultController::class, 'store'])->name('vaults.existing.store');
Route::post('vaults/existing/browse', [ExistingVaultController::class, 'browse'])->name('vaults.existing.browse');

Route::patch('vaults/{vault:uuid}', [VaultController::class, 'update'])->whereUuid('vault')->name('vaults.update');
Route::delete('vaults/{vault:uuid}', [VaultController::class, 'destroy'])->whereUuid('vault')->name('vaults.destroy');
Route::post('vaults/{vault:uuid}/open', [VaultController::class, 'open'])->whereUuid('vault')->name('vaults.open');
