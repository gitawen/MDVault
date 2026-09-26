<?php

use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', WorkspaceController::class)->name('workspace');

require __DIR__.'/settings.php';
