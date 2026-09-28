<?php

namespace App\Http\Controllers;

use App\Exceptions\NoteOperationException;
use App\Http\Requests\Notes\DestroyFolderRequest;
use App\Http\Requests\Notes\StoreFolderRequest;
use App\Models\Vault;
use App\Services\NoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class FolderController extends Controller
{
    public function store(StoreFolderRequest $request, Vault $vault, NoteService $notes): RedirectResponse
    {
        $relative = $this->attempt(fn (): string => $notes->createFolder($vault, $request->validated('parent'), $request->validated('name')));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Folder \u{201c}{$relative}\u{201d} created."]);

        return back();
    }

    public function destroy(DestroyFolderRequest $request, Vault $vault, NoteService $notes): RedirectResponse
    {
        $this->attempt(function () use ($vault, $notes, $request): void {
            $notes->deleteFolder($vault, $request->validated('path'));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Folder deleted.']);

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
        } catch (NoteOperationException $e) {
            throw ValidationException::withMessages([$e->field() => $e->getMessage()]);
        }
    }
}
