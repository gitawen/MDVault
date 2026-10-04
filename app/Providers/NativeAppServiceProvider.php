<?php

namespace App\Providers;

use App\Services\VaultRecoveryService;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        Window::open()
            ->title((string) config('app.name'))
            ->width(1280)
            ->height(800)
            ->minWidth(960)
            ->minHeight(600)
            ->preventLeaveDomain()
            ->rememberState();

        $this->recoverInterruptedConversions();
    }

    /**
     * Repairs any vault conversion a crash interrupted (ADR
     * `vault-encryption-conversion`). A failure is reported and never fatal.
     */
    private function recoverInterruptedConversions(): void
    {
        try {
            app(VaultRecoveryService::class)->recoverAll();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Return an array of php.ini directives to be set.
     *
     * @return array<string, string>
     */
    public function phpIni(): array
    {
        return [
        ];
    }
}
