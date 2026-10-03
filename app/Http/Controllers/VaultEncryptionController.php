<?php

namespace App\Http\Controllers;

use App\Exceptions\EncryptionException;
use App\Exceptions\VaultOperationException;
use App\Http\Requests\Vaults\DecryptVaultRequest;
use App\Http\Requests\Vaults\EncryptVaultRequest;
use App\Models\Vault;
use App\Services\VaultConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class VaultEncryptionController extends Controller
{
    /**
     * Encrypts an existing vault (JSON, so the password is never flashed).
     * The response carries the unlock token, or the problems that stopped
     * the conversion before anything was changed.
     */
    public function store(EncryptVaultRequest $request, Vault $vault, VaultConversionService $conversion): JsonResponse
    {
        set_time_limit(0);

        try {
            $result = $conversion->encrypt($vault, $request->validated('password'));
        } catch (VaultOperationException|EncryptionException $e) {
            $problems = $e instanceof EncryptionException ? $e->problems() : [];
            $errors = [$e->field() => [$e->getMessage()]];

            // One key per problem: the client shows the first message of each key.
            foreach ($problems as $index => $problem) {
                $errors['problem_'.$index] = [$problem];
            }

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $errors,
                'problems' => $problems,
            ], 422);
        }

        return response()->json(['token' => $result->token, 'warning' => $result->warning]);
    }

    /**
     * Removes the encryption from a vault. Needs the password.
     */
    public function destroy(DecryptVaultRequest $request, Vault $vault, VaultConversionService $conversion): RedirectResponse
    {
        set_time_limit(0);

        try {
            $result = $conversion->decrypt($vault, $request->validated('password'));
        } catch (VaultOperationException|EncryptionException $e) {
            $message = $e->getMessage();

            if ($e instanceof EncryptionException && $e->problems() !== []) {
                $message .= ' '.implode(' ', $e->problems());
            }

            throw ValidationException::withMessages([$e->field() => $message]);
        }

        Inertia::clearHistory();
        Inertia::flash('toast', [
            'type' => $result->warning === null ? 'success' : 'error',
            'message' => $result->warning ?? 'Encryption removed. This vault is no longer encrypted.',
        ]);

        return back();
    }
}
