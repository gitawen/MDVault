<?php

namespace App\Http\Controllers;

use App\Models\Vault;
use App\Services\VaultEncryptionService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class VaultLockController extends Controller
{
    /**
     * Locks one vault: its session entry is deleted and the renderer's
     * history (which may hold decrypted props) is cleared. This is an
     * Inertia visit, so the unsaved-changes guard flushes saves first.
     */
    public function store(Vault $vault, VaultEncryptionService $encryption): RedirectResponse
    {
        $encryption->lock($vault);

        Inertia::clearHistory();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Vault locked.']);

        return to_route('workspace');
    }

    /**
     * Locks every vault.
     */
    public function storeAll(VaultEncryptionService $encryption): RedirectResponse
    {
        $encryption->lockAll();

        Inertia::clearHistory();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'All vaults locked.']);

        return to_route('workspace');
    }
}
