<?php

namespace App\Http\Controllers;

use App\Enums\NoteSaveMode;
use App\Exceptions\NoteOperationException;
use App\Http\Requests\Notes\StoreNoteCopyRequest;
use App\Models\Vault;
use App\Services\NoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class NoteCopyController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(StoreNoteCopyRequest $request, Vault $vault, NoteService $notes): JsonResponse
    {
        try {
            $copy = $notes->createCopy(
                $vault,
                $request->validated('source_path'),
                $request->contentText(),
                NoteSaveMode::from($request->validated('mode')),
                $request->frontmatterEdit(),
            );
        } catch (NoteOperationException $e) {
            throw ValidationException::withMessages([$e->field() => $e->getMessage()]);
        }

        // An encrypted note's own title and path are opaque; the client gets
        // the logical ones (this JSON is never persisted).
        $title = $copy->title;
        $path = $copy->relative_path;

        if ($vault->is_encrypted) {
            $presented = $notes->present($copy);
            $title = $presented['title'];
            $path = $presented['relative_path'];
        }

        return response()->json([
            'uuid' => $copy->uuid,
            'title' => $title,
            'relative_path' => $path,
        ], 201);
    }
}
