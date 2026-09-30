<?php

namespace App\Http\Controllers;

use App\Models\Note;
use App\Services\NoteService;
use Illuminate\Http\JsonResponse;

class NoteDiskController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Note $note, NoteService $notes): JsonResponse
    {
        $preview = $notes->preview($note);

        return response()->json([
            'state' => $preview['state'],
            'content' => $preview['content'],
            'base_hash' => $preview['base_hash'],
        ]);
    }
}
