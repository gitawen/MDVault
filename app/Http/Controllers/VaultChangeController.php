<?php

namespace App\Http\Controllers;

use App\Http\Requests\Vaults\CheckVaultChangesRequest;
use App\Models\Vault;
use App\Services\ExternalChangeService;
use Illuminate\Http\JsonResponse;

class VaultChangeController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(CheckVaultChangesRequest $request, Vault $vault, ExternalChangeService $changes): JsonResponse
    {
        return response()->json($changes->check($vault, $request->validated('open_note')));
    }
}
