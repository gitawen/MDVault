<?php

namespace App\Http\Controllers;

use App\Exceptions\EncryptionException;
use App\Exceptions\VaultOperationException;
use App\Http\Requests\Vaults\StoreEncryptedVaultRequest;
use App\Services\VaultEncryptionService;
use App\Services\VaultService;
use Illuminate\Http\JsonResponse;

class EncryptedVaultController extends Controller
{
    /**
     * Creates an encrypted vault (JSON, so nothing is flashed to the
     * session) and opens it. The response carries the unlock token the
     * renderer must hold; it is the only place it is ever sent.
     */
    public function store(StoreEncryptedVaultRequest $request, VaultEncryptionService $encryption, VaultService $vaults): JsonResponse
    {
        try {
            [$vault, $token] = $encryption->createEncryptedVault(
                $request->validated('name'),
                $request->validated('description'),
                $request->validated('password'),
            );

            $vaults->open($vault);
        } catch (VaultOperationException|EncryptionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [$e->field() => [$e->getMessage()]],
            ], 422);
        }

        return response()->json(['uuid' => $vault->uuid, 'token' => $token], 201);
    }
}
