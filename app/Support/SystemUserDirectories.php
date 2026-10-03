<?php

namespace App\Support;

use App\Contracts\UserDirectories;
use Illuminate\Contracts\Config\Repository;

final class SystemUserDirectories implements UserDirectories
{
    /**
     * @param  array<string, string>  $environment
     */
    public function __construct(
        private readonly Repository $config,
        private readonly array $environment,
        private readonly string $osFamily,
    ) {}

    public function documentsPath(): ?string
    {
        $nativeDocuments = $this->config->get('filesystems.disks.documents.root');

        if (is_string($nativeDocuments) && $nativeDocuments !== '') {
            return $nativeDocuments;
        }

        $home = $this->homeDirectory();

        if ($home === null) {
            return null;
        }

        $separator = $this->isWindows() ? '\\' : '/';

        return $home.$separator.'Documents';
    }

    public function homeDirectory(): ?string
    {
        if ($this->isWindows()) {
            $home = $this->env('USERPROFILE');

            if ($home === null) {
                $homeDrive = $this->env('HOMEDRIVE');
                $homePath = $this->env('HOMEPATH');

                if ($homeDrive !== null && $homePath !== null) {
                    $home = $homeDrive.$homePath;
                }
            }

            $home ??= $this->env('HOME');
        } else {
            $home = $this->env('HOME');
        }

        if ($home === null) {
            return null;
        }

        return rtrim($home, '\\/');
    }

    private function env(string $key): ?string
    {
        $value = $this->environment[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isWindows(): bool
    {
        return $this->osFamily === 'Windows';
    }
}
