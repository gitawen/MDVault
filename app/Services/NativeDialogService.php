<?php

namespace App\Services;

use Illuminate\Contracts\Config\Repository;
use Native\Desktop\Dialog;

final class NativeDialogService
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * Whether the desktop runtime (and therefore the native dialog) is available.
     */
    public function isAvailable(): bool
    {
        return (bool) $this->config->get('nativephp-internal.running');
    }

    /**
     * Open the OS folder picker. Returns null when unavailable or cancelled.
     */
    public function chooseDirectory(string $title, ?string $defaultPath = null): ?string
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $dialog = Dialog::new()
            ->title($title)
            ->button('Select folder')
            ->properties(['openDirectory', 'createDirectory']);

        if ($defaultPath !== null && is_dir($defaultPath)) {
            $dialog->defaultPath($defaultPath);
        }

        $result = $dialog->open();

        return is_string($result) && $result !== '' ? $result : null;
    }
}
