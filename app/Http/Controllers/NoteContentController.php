<?php

namespace App\Http\Controllers;

use App\Enums\NoteSaveMode;
use App\Exceptions\NoteOperationException;
use App\Exceptions\NoteSaveConflictException;
use App\Http\Requests\Notes\SaveNoteContentRequest;
use App\Models\Note;
use App\Services\NoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class NoteContentController extends Controller
{
    public function __invoke(SaveNoteContentRequest $request, Note $note, NoteService $notes): JsonResponse
    {
        try {
            $result = $notes->save(
                $note,
                $request->contentText(),
                $request->validated('base_hash'),
                NoteSaveMode::from($request->validated('mode')),
                $request->frontmatterEdit(),
            );

            return response()->json($result->toArray());
        } catch (NoteSaveConflictException $e) {
            return response()->json([
                'reason' => $e->reason(),
                'message' => $e->getMessage(),
                'current_hash' => $e->currentHash(),
            ], 409);
        } catch (NoteOperationException $e) {
            throw ValidationException::withMessages([$e->field() => $e->getMessage()]);
        }
    }
}
