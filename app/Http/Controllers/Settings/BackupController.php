<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\BackupException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreBackupRequest;
use App\Models\Vault;
use App\Services\BackupService;
use App\Services\NativeDialogService;
use App\Services\StoragePathService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BackupController extends Controller
{
    public function edit(BackupService $backups, StoragePathService $paths, NativeDialogService $dialogs): Response
    {
        return Inertia::render('settings/Backup', [
            'backups' => $backups->recent(),
            'defaultDirectory' => $paths->defaultBackupDirectory(),
            'canBrowse' => $dialogs->isAvailable(),
        ]);
    }

    public function store(StoreBackupRequest $request, BackupService $backups, NativeDialogService $dialogs): RedirectResponse
    {
        $vaultUuid = $request->validated('vault');
        $vault = $vaultUuid === null ? null : Vault::query()->where('uuid', $vaultUuid)->firstOrFail();

        if ($dialogs->isAvailable()) {
            $destination = $dialogs->chooseSaveFile(
                'Save MDVault backup',
                $backups->defaultDestination($vault, false),
                'MDVault backup',
                ['zip'],
            );

            if ($destination === null) {
                return back();
            }
        } else {
            try {
                $destination = $backups->defaultDestination($vault, true);
            } catch (BackupException $e) {
                Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

                return back();
            }
        }

        try {
            $result = $backups->create($vault, $destination);
        } catch (BackupException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }

        $message = "Backup saved to {$result->path}. {$result->vaultCount} vault(s), {$result->noteCount} note(s).";
        $type = 'success';

        if ($result->skippedVaults !== []) {
            $message .= ' Skipped missing vault(s): '.implode(', ', $result->skippedVaults).'.';
        }

        if (! $result->recorded) {
            $message .= " It couldn't be added to the backup history.";
            $type = 'warning';
        }

        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
