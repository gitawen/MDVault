<?php

use App\Http\Controllers\FolderController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\VaultIndexController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('notes/{note:uuid}', WorkspaceController::class)->whereUuid('note')->name('notes.show');
Route::patch('notes/{note:uuid}', [NoteController::class, 'update'])->whereUuid('note')->name('notes.update');
Route::post('notes/{note:uuid}/move', [NoteController::class, 'move'])->whereUuid('note')->name('notes.move');
Route::delete('notes/{note:uuid}', [NoteController::class, 'destroy'])->whereUuid('note')->name('notes.destroy');

Route::post('vaults/{vault:uuid}/notes', [NoteController::class, 'store'])->whereUuid('vault')->name('vaults.notes.store');
Route::post('vaults/{vault:uuid}/folders', [FolderController::class, 'store'])->whereUuid('vault')->name('vaults.folders.store');
Route::delete('vaults/{vault:uuid}/folders', [FolderController::class, 'destroy'])->whereUuid('vault')->name('vaults.folders.destroy');
Route::post('vaults/{vault:uuid}/reindex', VaultIndexController::class)->whereUuid('vault')->name('vaults.reindex');
