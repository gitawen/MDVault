<?php

namespace App\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;

final class SystemStatusService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
    ) {}

    /**
     * Report the current application, runtime and database status.
     *
     * This is a health probe: it never throws, even when the database
     * connection is unreachable.
     *
     * @return array{application: string, version: string, runtime: 'desktop'|'browser', database: array{driver: string, connected: bool}}
     */
    public function summary(): array
    {
        return [
            'application' => (string) $this->config->get('app.name'),
            'version' => (string) $this->config->get('nativephp.version'),
            'runtime' => $this->isRunningAsDesktopApp() ? 'desktop' : 'browser',
            'database' => [
                'driver' => $this->databaseDriver(),
                'connected' => $this->databaseIsConnected(),
            ],
        ];
    }

    public function isRunningAsDesktopApp(): bool
    {
        return (bool) $this->config->get('nativephp-internal.running');
    }

    /**
     * Check database connectivity.
     *
     * This is a health probe: it never throws, even when the database
     * connection is unreachable.
     */
    public function databaseIsConnected(): bool
    {
        try {
            $this->database->connection()->select('select 1');

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Resolve the active database driver name.
     *
     * This is a health probe: it never throws, even when the database
     * connection is unreachable.
     */
    private function databaseDriver(): string
    {
        try {
            return $this->database->connection()->getDriverName();
        } catch (\Exception) {
            $default = $this->config->get('database.default');

            return (string) ($this->config->get("database.connections.{$default}.driver") ?? 'unknown');
        }
    }
}
