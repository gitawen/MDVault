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
        $result = $this->openDirectoryDialog(
            $title,
            'Select folder',
            ['openDirectory', 'createDirectory'],
            $defaultPath,
        );

        return is_string($result) && $result !== '' ? $result : null;
    }

    /**
     * Open the OS folder picker with multi-selection enabled. Returns an empty
     * list when unavailable or cancelled.
     *
     * @return list<string>
     */
    public function chooseDirectories(string $title, ?string $defaultPath = null): array
    {
        $result = $this->openDirectoryDialog(
            $title,
            'Select folders',
            ['openDirectory', 'createDirectory', 'multiSelections'],
            $defaultPath,
        );

        if (! is_array($result)) {
            return [];
        }

        $directories = [];

        foreach ($result as $entry) {
            if (is_string($entry) && $entry !== '' && ! in_array($entry, $directories, true)) {
                $directories[] = $entry;
            }
        }

        return $directories;
    }

    /**
     * @param  list<string>  $properties
     */
    private function openDirectoryDialog(string $title, string $buttonLabel, array $properties, ?string $defaultPath): mixed
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $dialog = Dialog::new()
            ->title($title)
            ->button($buttonLabel)
            ->properties($properties);

        if ($defaultPath !== null && is_dir($defaultPath)) {
            $dialog->defaultPath($defaultPath);
        }

        return $dialog->open();
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
