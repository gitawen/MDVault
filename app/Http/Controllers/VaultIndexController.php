<?php

namespace App\Http\Controllers;

use App\Exceptions\NoteOperationException;
use App\Models\Vault;
use App\Services\VaultIndexService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class VaultIndexController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Vault $vault, VaultIndexService $index): RedirectResponse
    {
        try {
            $result = $index->reindex($vault);

            Inertia::flash('toast', ['type' => 'success', 'message' => $result->summary()]);
        } catch (NoteOperationException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back();
    }
}
