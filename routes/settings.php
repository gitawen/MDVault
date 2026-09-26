<?php

use Illuminate\Support\Facades\Route;

Route::redirect('settings', '/settings/appearance')->name('settings');

Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
