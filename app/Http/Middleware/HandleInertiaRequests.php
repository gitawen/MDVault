<?php

namespace App\Http\Middleware;

use App\Enums\SettingKey;
use App\Services\SettingsService;
use App\Services\VaultService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly VaultService $vaults,
        private readonly SettingsService $settings,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'vaults' => fn () => $this->vaults->summaries(),
            'security' => fn () => [
                'auto_lock_minutes' => $this->settings->integer(SettingKey::SecurityAutoLockMinutes),
                'lock_on_screen_lock' => $this->settings->boolean(SettingKey::SecurityLockOnScreenLock),
            ],
        ];
    }
}
