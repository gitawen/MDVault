<?php

namespace App\Http\Controllers;

use App\Exceptions\NoteOperationException;
use App\Http\Requests\Notes\MoveNoteRequest;
use App\Http\Requests\Notes\StoreNoteRequest;
use App\Http\Requests\Notes\UpdateNoteRequest;
use App\Models\Note;
use App\Models\Vault;
use App\Services\NoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class NoteController extends Controller
{
    public function store(StoreNoteRequest $request, Vault $vault, NoteService $notes): RedirectResponse
    {
        $note = $this->attempt(fn (): Note => $notes->create($vault, $request->validated('folder'), $request->validated('name'), $request->validated('timezone')));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Note \u{201c}{$note->title}\u{201d} created."]);

        return to_route('notes.show', $note->uuid);
    }

    public function update(UpdateNoteRequest $request, Note $note, NoteService $notes): RedirectResponse
    {
        $before = $note->relative_path;

        $this->attempt(fn (): Note => $notes->rename($note, $request->validated('name')));

        $message = $note->relative_path !== $before ? 'Note renamed.' : 'Nothing to change.';

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('notes.show', $note->uuid);
    }

    public function move(MoveNoteRequest $request, Note $note, NoteService $notes): RedirectResponse
    {
        $before = $note->relative_path;
        $folder = $request->validated('folder');

        $this->attempt(fn (): Note => $notes->move($note, $folder));

        $message = $note->relative_path !== $before
            ? 'Note moved to '.($folder ?: 'the top level').'.'
            : 'Nothing to change.';

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('notes.show', $note->uuid);
    }

    public function destroy(Note $note, NoteService $notes): RedirectResponse
    {
        $title = $note->title;

        $trashed = $this->attempt(fn (): bool => $notes->delete($note));

        $message = $trashed
            ? "Note \u{201c}{$title}\u{201d} moved to the Recycle Bin / Trash."
            : "Note \u{201c}{$title}\u{201d} was already gone from disk and has been removed from the list.";

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('workspace');
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
