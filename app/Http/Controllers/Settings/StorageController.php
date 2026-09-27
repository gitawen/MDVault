<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\InvalidStorageRootException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\BrowseStorageRootRequest;
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
        $this->changeRoot($paths, $request->validated('location'), $request->validated('folder_name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Storage location updated.']);

        return back();
    }

    public function browse(BrowseStorageRootRequest $request, StoragePathService $paths, NativeDialogService $dialogs): RedirectResponse
    {
        $chosen = $dialogs->chooseDirectory('Choose where MDVault stores your vaults', $paths->rootPath());

        if ($chosen === null) {
            return back();
        }

        $this->changeRoot($paths, $chosen, $request->validated('folder_name'));

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
    private function changeRoot(StoragePathService $paths, string $location, string $folderName): void
    {
        try {
            $paths->changeRoot($location, $folderName);
        } catch (InvalidStorageRootException $e) {
            throw ValidationException::withMessages([$e->field() => $e->getMessage()]);
        }
    }
}
