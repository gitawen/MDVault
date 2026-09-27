<?php

namespace App\Http\Controllers;

use App\Enums\SettingGroup;
use App\Services\SettingsService;
use App\Services\SystemStatusService;
use App\Services\VaultService;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(SystemStatusService $systemStatus, SettingsService $settings, VaultService $vaults): Response
    {
        $current = $vaults->current();

        return Inertia::render('Workspace', [
            'status' => $systemStatus->summary(),
            'editor' => $settings->group(SettingGroup::Editor),
            'currentVault' => $current ? $vaults->present($current) : null,
        ]);
    }
}
