<?php

use App\Http\Controllers\DirectoryBrowserController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', WorkspaceController::class)->name('workspace');
Route::post('directories/browse', DirectoryBrowserController::class)->name('directories.browse');

require __DIR__.'/settings.php';
require __DIR__.'/vaults.php';
require __DIR__.'/notes.php';
