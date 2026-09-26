<?php

use App\Services\SystemStatusService;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

test('summary reports a connected sqlite database in browser runtime', function () {
    $service = app(SystemStatusService::class);

    $summary = $service->summary();

    expect($summary)->toMatchArray([
        'application' => config('app.name'),
        'runtime' => 'browser',
        'database' => [
            'driver' => 'sqlite',
            'connected' => true,
        ],
    ])->and($summary['version'])->toBeString();
});

test('summary reports a disconnected database without throwing when the connection fails', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('select')->andThrow(new PDOException('unavailable'));
    $connection->shouldReceive('getDriverName')->andReturn('sqlite');

    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('connection')->andReturn($connection);

    $service = new SystemStatusService($database, config());

    $summary = $service->summary();

    expect($summary['database'])->toMatchArray([
        'driver' => 'sqlite',
        'connected' => false,
    ]);
});

test('runtime is desktop when running under nativephp', function () {
    config(['nativephp-internal.running' => true]);

    $service = app(SystemStatusService::class);

    expect($service->summary()['runtime'])->toBe('desktop');
});

test('runtime is browser by default', function () {
    $service = app(SystemStatusService::class);

    expect($service->summary()['runtime'])->toBe('browser');
});
