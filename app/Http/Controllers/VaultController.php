<?php

namespace App\Http\Controllers;

use App\Exceptions\NoteOperationException;
use App\Exceptions\VaultOperationException;
use App\Http\Requests\Vaults\DestroyVaultRequest;
use App\Http\Requests\Vaults\StoreVaultRequest;
use App\Http\Requests\Vaults\UpdateVaultRequest;
use App\Models\Vault;
use App\Services\NativeDialogService;
use App\Services\StoragePathService;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class VaultController extends Controller
{
    public function index(StoragePathService $paths, VaultService $vaults, NativeDialogService $dialogs): Response
    {
        return Inertia::render('vaults/Index', [
            'storageRoot' => $paths->rootPath(),
            'canBrowse' => $dialogs->isAvailable(),
            'canTrash' => $vaults->canTrash(),
        ]);
    }

    public function store(StoreVaultRequest $request, VaultService $vaults, VaultIndexService $index): RedirectResponse
    {
        $vault = $this->attempt(fn (): Vault => $vaults->create($request->validated('name'), $request->validated('description')));

        $vaults->open($vault);

        try {
            $index->reindex($vault);
        } catch (NoteOperationException) {
            // The vault was just created; nothing to reindex yet.
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Vault \u{201c}{$vault->name}\u{201d} created."]);

        return to_route('workspace');
    }

    public function update(UpdateVaultRequest $request, Vault $vault, VaultService $vaults): RedirectResponse
    {
        $before = $vault->path;

        $this->attempt(fn (): Vault => $vaults->rename($vault, $request->validated('name'), $request->validated('description')));

        $message = $vault->path !== $before
            ? "Vault renamed. Its folder is now {$vault->path}."
            : 'Vault updated.';

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function destroy(DestroyVaultRequest $request, Vault $vault, VaultService $vaults): RedirectResponse
    {
        $name = $vault->name;
        $moveToTrash = $request->boolean('move_to_trash');

        $this->attempt(function () use ($vaults, $vault, $moveToTrash): void {
            $vaults->remove($vault, $moveToTrash);
        });

        $suffix = $moveToTrash
            ? ' Its folder was moved to the Recycle Bin / Trash.'
            : ' Its files were left on disk.';

        Inertia::flash('toast', ['type' => 'success', 'message' => "Vault \u{201c}{$name}\u{201d} removed.{$suffix}"]);

        return to_route('vaults.index');
    }

    public function open(Vault $vault, VaultService $vaults, VaultIndexService $index): RedirectResponse
    {
        try {
            $vaults->open($vault);
        } catch (VaultOperationException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }

        try {
            $result = $index->reindex($vault);

            if ($result->hasChanges() || $result->skipped > 0) {
                Inertia::flash('toast', ['type' => 'success', 'message' => $result->summary()]);
            }
        } catch (NoteOperationException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return to_route('workspace');
    }

    public function close(VaultService $vaults): RedirectResponse
    {
        $vaults->close();

        return to_route('workspace');
    }

    /**
     * Runs a service call, mapping a VaultOperationException to a
     * ValidationException on the exception's own field (the same pattern
     * as StorageController).
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     *
     * @throws ValidationException
     */
    private function attempt(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (VaultOperationException $e) {
            throw ValidationException::withMessages([$e->field() => $e->getMessage()]);
        }
    }
}
