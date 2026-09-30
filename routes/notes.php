<?php

use App\Http\Controllers\FolderController;
use App\Http\Controllers\NoteContentController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\NoteCopyController;
use App\Http\Controllers\NoteDiskController;
use App\Http\Controllers\VaultChangeController;
use App\Http\Controllers\VaultIndexController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('notes/{note:uuid}', WorkspaceController::class)
    ->whereUuid('note')
    ->name('notes.show')
    ->missing(function (Request $request) {
        // A tree/folders/treeSignature partial reload for a note the
        // external-change check just reconciled away must not 404: that
        // would open an Inertia error modal and loop (AR-01). A full visit
        // (no partial-data header) still 404s, unchanged.
        if ($request->header('X-Inertia-Partial-Data')) {
            return app()->call([app(WorkspaceController::class), '__invoke'], ['note' => null]);
        }

        abort(404);
    });
Route::patch('notes/{note:uuid}', [NoteController::class, 'update'])->whereUuid('note')->name('notes.update');
Route::put('notes/{note:uuid}/content', NoteContentController::class)->whereUuid('note')->name('notes.content.update');
Route::post('notes/{note:uuid}/move', [NoteController::class, 'move'])->whereUuid('note')->name('notes.move');
Route::delete('notes/{note:uuid}', [NoteController::class, 'destroy'])->whereUuid('note')->name('notes.destroy');
Route::get('notes/{note:uuid}/disk', NoteDiskController::class)->whereUuid('note')->name('notes.disk.show');

Route::post('vaults/{vault:uuid}/notes/copy', NoteCopyController::class)->whereUuid('vault')->name('vaults.notes.copy');
Route::post('vaults/{vault:uuid}/notes', [NoteController::class, 'store'])->whereUuid('vault')->name('vaults.notes.store');
Route::post('vaults/{vault:uuid}/folders', [FolderController::class, 'store'])->whereUuid('vault')->name('vaults.folders.store');
Route::delete('vaults/{vault:uuid}/folders', [FolderController::class, 'destroy'])->whereUuid('vault')->name('vaults.folders.destroy');
Route::post('vaults/{vault:uuid}/reindex', VaultIndexController::class)->whereUuid('vault')->name('vaults.reindex');
Route::post('vaults/{vault:uuid}/changes', VaultChangeController::class)->whereUuid('vault')->name('vaults.changes.check');
