<?php

namespace App\Services;

use App\Contracts\UserDirectories;

final class DirectoryBrowserService
{
    public function __construct(
        private readonly StoragePathService $storagePaths,
        private readonly UserDirectories $userDirectories,
    ) {}

    /**
     * Browse the filesystem starting from $targetPath or default user/vault directory.
     *
     * @return array{
     *     current_path: string,
     *     parent_path: ?string,
     *     breadcrumbs: list<array{name: string, path: string}>,
     *     drives: list<array{name: string, path: string}>,
     *     quick_links: list<array{name: string, path: string, icon: string}>,
     *     directories: list<array{name: string, path: string}>,
     *     is_writable: bool,
     * }
     */
    public function browse(?string $targetPath = null): array
    {
        $isWindows = PHP_OS_FAMILY === 'Windows';
        $current = $this->resolveCurrentPath($targetPath, $isWindows);

        return [
            'current_path' => $current,
            'parent_path' => $this->resolveParentPath($current, $isWindows),
            'breadcrumbs' => $this->buildBreadcrumbs($current, $isWindows),
            'drives' => $this->discoverDrives($isWindows),
            'quick_links' => $this->buildQuickLinks(),
            'directories' => $this->listDirectories($current, $isWindows),
            'is_writable' => is_dir($current) && is_writable($current),
        ];
    }

    private function resolveCurrentPath(?string $targetPath, bool $isWindows): string
    {
        if (is_string($targetPath) && trim($targetPath) !== '') {
            $normalized = $this->normalizePath(trim($targetPath), $isWindows);

            // Traverse up until we find an existing directory
            $cursor = $normalized;
            while ($cursor !== '' && $cursor !== '.' && ! @is_dir($cursor)) {
                $parent = $this->resolveParentPath($cursor, $isWindows);
                if ($parent === null || $parent === $cursor) {
                    break;
                }
                $cursor = $parent;
            }

            if ($cursor !== '' && @is_dir($cursor)) {
                return $cursor;
            }
        }

        // Default fallbacks
        try {
            $root = $this->storagePaths->rootPath();
            if (@is_dir($root)) {
                return $this->normalizePath($root, $isWindows);
            }
        } catch (\Throwable) {
            // Ignore storage path resolution errors
        }

        $docs = $this->userDirectories->documentsPath();
        if ($docs !== null && @is_dir($docs)) {
            return $this->normalizePath($docs, $isWindows);
        }

        $home = $this->userDirectories->homeDirectory();
        if ($home !== null && @is_dir($home)) {
            return $this->normalizePath($home, $isWindows);
        }

        return $this->normalizePath(base_path(), $isWindows);
    }

    private function normalizePath(string $path, bool $isWindows): string
    {
        $real = @realpath($path);
        if ($real !== false) {
            $path = $real;
        }

        if ($isWindows) {
            $path = str_replace('/', '\\', $path);
            if (preg_match('/^[A-Za-z]:$/', $path)) {
                $path .= '\\';
            } else {
                $path = rtrim($path, '\\');
                if (preg_match('/^[A-Za-z]:$/', $path)) {
                    $path .= '\\';
                }
            }
        } else {
            $path = str_replace('\\', '/', $path);
            $path = $path === '/' ? '/' : rtrim($path, '/');
        }

        return $path;
    }

    private function resolveParentPath(string $current, bool $isWindows): ?string
    {
        if ($isWindows) {
            if (preg_match('/^[A-Za-z]:\\\\?$/', $current)) {
                return null;
            }

            $parent = dirname($current);
            if (preg_match('/^[A-Za-z]:$/', $parent)) {
                return $parent.'\\';
            }

            return $parent !== $current ? $parent : null;
        }

        if ($current === '/' || $current === '') {
            return null;
        }

        $parent = dirname($current);

        return $parent !== $current ? $parent : null;
    }

    /**
     * @return list<array{name: string, path: string}>
     */
    private function buildBreadcrumbs(string $current, bool $isWindows): array
    {
        $breadcrumbs = [];

        if ($isWindows) {
            $parts = array_values(array_filter(explode('\\', $current), fn ($p) => $p !== ''));
            $acc = '';
            foreach ($parts as $index => $part) {
                if ($index === 0) {
                    $acc = $part.'\\';
                } else {
                    $acc = rtrim($acc, '\\').'\\'.$part;
                }
                $breadcrumbs[] = [
                    'name' => $part,
                    'path' => $acc,
                ];
            }
        } else {
            $parts = array_values(array_filter(explode('/', $current), fn ($p) => $p !== ''));
            $breadcrumbs[] = [
                'name' => '/',
                'path' => '/',
            ];
            $acc = '';
            foreach ($parts as $part) {
                $acc .= '/'.$part;
                $breadcrumbs[] = [
                    'name' => $part,
                    'path' => $acc,
                ];
            }
        }

        return $breadcrumbs;
    }

    /**
     * @return list<array{name: string, path: string}>
     */
    private function discoverDrives(bool $isWindows): array
    {
        if (! $isWindows) {
            return [
                ['name' => '/', 'path' => '/'],
            ];
        }

        $drives = [];
        foreach (range('A', 'Z') as $letter) {
            $root = $letter.':\\';
            if (@is_dir($root)) {
                $drives[] = [
                    'name' => $letter.':',
                    'path' => $root,
                ];
            }
        }

        return $drives;
    }

    /**
     * @return list<array{name: string, path: string, icon: string}>
     */
    private function buildQuickLinks(): array
    {
        $links = [];

        try {
            $root = $this->storagePaths->rootPath();
            if (@is_dir($root)) {
                $links[] = [
                    'name' => 'Storage Root',
                    'path' => $root,
                    'icon' => 'vault',
                ];
            }
        } catch (\Throwable) {
        }

        $docs = $this->userDirectories->documentsPath();
        if ($docs !== null && @is_dir($docs)) {
            $links[] = [
                'name' => 'Documents',
                'path' => $docs,
                'icon' => 'folder',
            ];
        }

        $home = $this->userDirectories->homeDirectory();
        if ($home !== null && @is_dir($home)) {
            $links[] = [
                'name' => 'Home',
                'path' => $home,
                'icon' => 'home',
            ];
        }

        return $links;
    }

    /**
     * @return list<array{name: string, path: string}>
     */
    private function listDirectories(string $current, bool $isWindows): array
    {
        if (! @is_dir($current) || ! @is_readable($current)) {
            return [];
        }

        $entries = @scandir($current);
        if ($entries === false) {
            return [];
        }

        $sep = $isWindows ? '\\' : '/';
        $directories = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (str_starts_with($entry, '.')) {
                continue;
            }

            if ($isWindows && in_array(strtoupper($entry), [
                '$RECYCLE.BIN',
                'SYSTEM VOLUME INFORMATION',
                'RECOVERY',
                'DUMPSTACK.LOG',
            ], true)) {
                continue;
            }

            $fullPath = rtrim($current, '\\/').$sep.$entry;

            if (@is_dir($fullPath)) {
                $directories[] = [
                    'name' => $entry,
                    'path' => $fullPath,
                ];
            }
        }

        usort($directories, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return array_values($directories);
    }
}
