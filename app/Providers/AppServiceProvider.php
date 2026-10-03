<?php

namespace App\Providers;

use App\Contracts\Trash;
use App\Contracts\UserDirectories;
use App\Enums\SettingKey;
use App\Services\EncryptedNoteService;
use App\Services\SettingsService;
use App\Services\VaultKeyService;
use App\Support\NativeTrash;
use App\Support\SystemUserDirectories;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Native\Desktop\Events\PowerMonitor\ScreenLocked;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(SettingsService::class);
        $this->app->scoped(VaultKeyService::class);
        $this->app->scoped(EncryptedNoteService::class);

        // Runtime getenv() is deliberate: this must not move into a config
        // file, because config:cache would bake the build machine's home
        // directory into the packaged app.
        $this->app->bind(UserDirectories::class, fn (Application $app) => new SystemUserDirectories(
            $app->make('config'),
            getenv(),
            PHP_OS_FAMILY,
        ));

        $this->app->bind(Trash::class, NativeTrash::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->lockVaultsWhenScreenLocks();
    }

    /**
     * The OS screen lock invalidates every unlocked vault in every session
     * (ADR `encrypted-vault-key-custody`), when the setting is on.
     */
    protected function lockVaultsWhenScreenLocks(): void
    {
        Event::listen(ScreenLocked::class, function (): void {
            if ($this->app->make(SettingsService::class)->boolean(SettingKey::SecurityLockOnScreenLock)) {
                $this->app->make(VaultKeyService::class)->lockEverywhere();
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );
    }
}
