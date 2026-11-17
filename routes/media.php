<?php

use App\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

Route::post('vaults/{vault:uuid}/media', [MediaController::class, 'store'])
    ->whereUuid('vault')
    ->name('vaults.media.store');

Route::get('media/{filename}', [MediaController::class, 'show'])
    ->where('filename', '[A-Za-z0-9][A-Za-z0-9._-]*')
    ->name('media.show');
