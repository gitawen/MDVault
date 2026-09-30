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

    /**
     * Open the OS file picker, filtered to one extension group. Returns
     * null when unavailable, cancelled or empty.
     *
     * @param  list<string>  $extensions
     */
    public function chooseFile(string $title, string $filterName, array $extensions, ?string $defaultPath = null): ?string
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $dialog = Dialog::new()
            ->title($title)
            ->files()
            ->filter($filterName, $extensions)
            ->button('Open');

        if ($defaultPath !== null && is_dir($defaultPath)) {
            $dialog->defaultPath($defaultPath);
        }

        $result = $dialog->open();

        return is_string($result) && $result !== '' ? $result : null;
    }

    /**
     * Open the OS Save dialog, pre-filled with $defaultPath. Returns null
     * when unavailable, cancelled or empty.
     *
     * @param  list<string>  $extensions
     */
    public function chooseSaveFile(string $title, string $defaultPath, string $filterName, array $extensions): ?string
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $result = Dialog::new()
            ->title($title)
            ->button('Save')
            ->defaultPath($defaultPath)
            ->filter($filterName, $extensions)
            ->properties(['createDirectory', 'showOverwriteConfirmation'])
            ->save();

        return is_string($result) && $result !== '' ? $result : null;
    }
}
