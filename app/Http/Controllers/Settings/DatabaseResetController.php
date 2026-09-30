<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ResetDatabaseRequest;
use App\Services\VaultService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class DatabaseResetController extends Controller
{
    public function destroy(ResetDatabaseRequest $request, VaultService $vaults): RedirectResponse
    {
        try {
            $result = $vaults->resetRegistry();
        } catch (QueryException $e) {
            report($e);

            Inertia::flash('toast', ['type' => 'error', 'message' => "The database couldn't be reset. Nothing was changed."]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $result->summary().' You can now restore a backup.']);

        return to_route('settings.backup.edit');
    }
}
