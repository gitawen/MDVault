<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\InvalidStorageRootException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateStorageRootRequest;
use App\Services\NativeDialogService;
use App\Services\StoragePathService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StorageController extends Controller
{
    public function edit(StoragePathService $paths, NativeDialogService $dialogs): Response
    {
        return Inertia::render('settings/Storage', [
            'storage' => $paths->summary(),
            'canBrowse' => $dialogs->isAvailable(),
        ]);
    }

    public function update(UpdateStorageRootRequest $request, StoragePathService $paths): RedirectResponse
    {
        $this->changeRoot($paths, $request->validated('root_path'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Storage location updated.']);

        return back();
    }

    public function browse(StoragePathService $paths, NativeDialogService $dialogs): RedirectResponse
    {
        abort_unless($dialogs->isAvailable(), 404);

        $chosen = $dialogs->chooseDirectory('Choose where MDVault stores your vaults', $paths->rootPath());

        if ($chosen === null) {
            return back();
        }

        $this->changeRoot($paths, $chosen);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Storage location updated.']);

        return back();
    }

    public function destroy(StoragePathService $paths): RedirectResponse
    {
        $paths->resetToDefault();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Storage location reset to default.']);

        return back();
    }

    /**
     * @throws ValidationException
     */
    private function changeRoot(StoragePathService $paths, string $rootPath): void
    {
        try {
            $paths->changeRoot($rootPath);
        } catch (InvalidStorageRootException $e) {
            throw ValidationException::withMessages(['root_path' => $e->getMessage()]);
        }
    }
}
