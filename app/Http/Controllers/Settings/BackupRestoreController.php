<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\BackupException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\InspectBackupRequest;
use App\Http\Requests\Settings\RestoreBackupRequest;
use App\Services\BackupService;
use App\Services\NativeDialogService;
use App\Services\StoragePathService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class BackupRestoreController extends Controller
{
    /**
     * Opens the native "Choose an MDVault backup" dialog. Only available in
     * the desktop runtime (404 outside it, matching the storage browse
     * route).
     */
    public function browse(StoragePathService $paths, NativeDialogService $dialogs, BackupService $backups): RedirectResponse
    {
        abort_unless($dialogs->isAvailable(), 404);

        $chosen = $dialogs->chooseFile(
            'Choose an MDVault backup',
            'MDVault backup',
            ['zip'],
            $paths->defaultBackupDirectory(),
        );

        if ($chosen === null) {
            return back();
        }

        Inertia::flash('pickedBackup', ['path' => $chosen]);

        return back();
    }

    public function inspect(InspectBackupRequest $request, BackupService $backups): JsonResponse
    {
        try {
            return response()->json($backups->inspect($request->validated('path'))->toArray());
        } catch (BackupException $e) {
            throw ValidationException::withMessages(['path' => $e->getMessage()]);
        }
    }

    public function store(RestoreBackupRequest $request, BackupService $backups): RedirectResponse
    {
        try {
            $result = $backups->restore($request->validated('path'), $request->actions());
        } catch (BackupException $e) {
            throw ValidationException::withMessages([$e->field() => $e->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => $result->warnings !== [] ? 'warning' : 'success',
            'message' => $result->summary(),
        ]);

        return to_route('vaults.index');
    }
}
