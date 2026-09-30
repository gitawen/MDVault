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

        return response()->json([
            'uuid' => $copy->uuid,
            'title' => $copy->title,
            'relative_path' => $copy->relative_path,
        ], 201);
    }
}
