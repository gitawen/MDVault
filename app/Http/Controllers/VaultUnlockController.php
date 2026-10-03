<?php

namespace App\Http\Controllers;

use App\Exceptions\EncryptionException;
use App\Exceptions\VaultOperationException;
use App\Http\Requests\Vaults\UnlockVaultRequest;
use App\Models\Vault;
use App\Services\VaultEncryptionService;
use Illuminate\Http\JsonResponse;

class VaultUnlockController extends Controller
{
    /**
     * Unlocks a vault with its password (JSON). The response is exactly
     * `{token}`; a failure is a generic 422 and leaves no session entry.
     */
    public function store(UnlockVaultRequest $request, Vault $vault, VaultEncryptionService $encryption): JsonResponse
    {
        try {
            $token = $encryption->unlock($vault, $request->validated('password'));
        } catch (VaultOperationException|EncryptionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [$e->field() => [$e->getMessage()]],
            ], 422);
        }

        return response()->json(['token' => $token]);
    }
}
