<?php

namespace App\Http\Controllers;

use App\Enums\IndexMode;
use App\Exceptions\NoteOperationException;
use App\Exceptions\VaultOperationException;
use App\Http\Requests\Vaults\RegisterVaultBatchRequest;
use App\Http\Requests\Vaults\RegisterVaultRequest;
use App\Models\Vault;
use App\Services\NativeDialogService;
use App\Services\StoragePathService;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use App\Support\VaultBatchResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ExistingVaultController extends Controller
{
    public function store(RegisterVaultRequest $request, VaultService $vaults, VaultIndexService $index): RedirectResponse
    {
        $vault = $this->attempt(fn (): Vault => $vaults->register(
            $request->validated('path'),
            $request->validated('name'),
            $request->validated('description'),
        ));

        $vaults->open($vault);

        $suffix = '';

        try {
            $result = $index->reconcile($vault, IndexMode::Quick);
            $suffix = " {$result->added} note(s) indexed.";
        } catch (NoteOperationException) {
            // Nothing to index if the folder can't be read right now.
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Vault \u{201c}{$vault->name}\u{201d} added.{$suffix}"]);

        return to_route('workspace');
    }

    public function batchStore(RegisterVaultBatchRequest $request, VaultService $vaults, VaultIndexService $index): RedirectResponse
    {
        $added = [];
        $skipped = [];
        $totalNotesIndexed = 0;

        /** @var list<string> $paths */
        $paths = $request->validated('paths');

        foreach ($paths as $path) {
            $name = $vaults->suggestedNameFor($path);

            try {
                $vault = $vaults->register($path, $name);

                try {
                    $result = $index->reconcile($vault, IndexMode::Quick);
                    $totalNotesIndexed += $result->added;
                } catch (NoteOperationException) {
                    // Nothing to index if the folder can't be read right now.
                }

                $added[] = $vault;
            } catch (VaultOperationException $e) {
                $skipped[] = [
                    'path' => $path,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        if (empty($added)) {
            $reasons = array_map(
                fn (array $item): string => $vaults->suggestedNameFor($item['path']).' ('.$item['reason'].')',
                $skipped
            );

            throw ValidationException::withMessages([
                'paths' => 'None of the selected folders could be registered: '.implode('; ', $reasons),
            ]);
        }

        $vaults->open($added[0]);

        $result = new VaultBatchResult($added, $skipped, $totalNotesIndexed);

        Inertia::flash('toast', [
            'type' => $result->skippedCount() > 0 ? 'warning' : 'success',
            'message' => $result->summary(),
        ]);

        if ($result->addedCount() === 1 && $result->skippedCount() === 0) {
            return to_route('workspace');
        }

        return to_route('vaults.index');
    }

    public function browse(StoragePathService $paths, NativeDialogService $dialogs, VaultService $vaults): RedirectResponse
    {
        abort_unless($dialogs->isAvailable(), 404);

        $chosen = $dialogs->chooseDirectory('Choose a folder to add as a vault', $paths->rootPath());

        if ($chosen === null) {
            return back();
        }

        Inertia::flash('pickedFolder', ['path' => $chosen, 'name' => $vaults->suggestedNameFor($chosen)]);

        return back();
    }

    /**
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
