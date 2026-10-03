<?php

namespace App\Http\Controllers;

use App\Exceptions\EncryptionException;
use App\Exceptions\VaultOperationException;
use App\Http\Requests\Vaults\UpdateVaultPasswordRequest;
use App\Models\Vault;
use App\Services\VaultEncryptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class VaultPasswordController extends Controller
{
    /**
     * Changes a vault's password. Only the key file is rewritten; the notes
     * stay as they are and the vault stays unlocked.
     */
    public function update(UpdateVaultPasswordRequest $request, Vault $vault, VaultEncryptionService $encryption): RedirectResponse
    {
        try {
            $encryption->changePassword($vault, $request->validated('current_password'), $request->validated('password'));
        } catch (VaultOperationException|EncryptionException $e) {
            $field = $e->field() === 'password' ? 'current_password' : $e->field();

            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Password changed. Older backups still open with the old password.']);

        return back();
    }
}
