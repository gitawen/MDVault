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

        // Encrypted vaults never put a decrypted name in a toast (the toast
        // is flashed to the session).
        $message = $vault->is_encrypted ? 'Note created.' : "Note \u{201c}{$note->title}\u{201d} created.";

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('notes.show', $note->uuid);
    }

    public function update(UpdateNoteRequest $request, Note $note, NoteService $notes): RedirectResponse
    {
        // A rename in an encrypted vault rewrites the file in place, so the
        // opaque path doesn't change: the ciphertext hash does.
        $before = $note->vault->is_encrypted ? $note->file_hash : $note->relative_path;

        $this->attempt(fn (): Note => $notes->rename($note, $request->validated('name')));

        $after = $note->vault->is_encrypted ? $note->file_hash : $note->relative_path;
        $message = $after !== $before ? 'Note renamed.' : 'Nothing to change.';

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('notes.show', $note->uuid);
    }

    public function move(MoveNoteRequest $request, Note $note, NoteService $notes): RedirectResponse
    {
        $before = $note->relative_path;
        $folder = $request->validated('folder');

        $this->attempt(fn (): Note => $notes->move($note, $folder));

        $destination = $note->vault->is_encrypted ? 'the new folder' : ($folder ?: 'the top level');
        $message = $note->relative_path !== $before
            ? "Note moved to {$destination}."
            : 'Nothing to change.';

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('notes.show', $note->uuid);
    }

    public function destroy(Note $note, NoteService $notes): RedirectResponse
    {
        $encrypted = $note->vault->is_encrypted;
        $title = $note->title;

        $trashed = $this->attempt(fn (): bool => $notes->delete($note));

        $subject = $encrypted ? 'Note' : "Note \u{201c}{$title}\u{201d}";
        $message = $trashed
            ? "{$subject} moved to the Recycle Bin / Trash."
            : "{$subject} was already gone from disk and has been removed from the list.";

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
