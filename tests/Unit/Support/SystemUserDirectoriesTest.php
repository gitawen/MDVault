<?php

use App\Support\SystemUserDirectories;
use Illuminate\Config\Repository;

function makeUserDirectories(array $config, array $environment, string $osFamily): SystemUserDirectories
{
    return new SystemUserDirectories(new Repository($config), $environment, $osFamily);
}

test('the native documents disk wins over the environment', function () {
    $directories = makeUserDirectories(
        config: ['filesystems' => ['disks' => ['documents' => ['root' => '/native/Documents']]]],
        environment: ['HOME' => '/home/someone'],
        osFamily: 'Linux',
    );

    expect($directories->documentsPath())->toBe('/native/Documents');
});

test('windows USERPROFILE resolves to its Documents folder', function () {
    $directories = makeUserDirectories(
        config: [],
        environment: ['USERPROFILE' => 'C:\\Users\\alice'],
        osFamily: 'Windows',
    );

    expect($directories->documentsPath())->toBe('C:\\Users\\alice\\Documents');
});

test('windows falls back to HOMEDRIVE and HOMEPATH', function () {
    $directories = makeUserDirectories(
        config: [],
        environment: ['HOMEDRIVE' => 'C:', 'HOMEPATH' => '\\Users\\alice'],
        osFamily: 'Windows',
    );

    expect($directories->documentsPath())->toBe('C:\\Users\\alice\\Documents');
});

test('linux and darwin resolve HOME to its Documents folder', function (string $osFamily) {
    $directories = makeUserDirectories(
        config: [],
        environment: ['HOME' => '/home/alice'],
        osFamily: $osFamily,
    );

    expect($directories->documentsPath())->toBe('/home/alice/Documents');
})->with(['Linux', 'Darwin']);

test('a trailing separator on home is trimmed', function () {
    $directories = makeUserDirectories(
        config: [],
        environment: ['HOME' => '/home/alice/'],
        osFamily: 'Linux',
    );

    expect($directories->documentsPath())->toBe('/home/alice/Documents');
});

test('no environment and no native disk resolves to null', function () {
    $directories = makeUserDirectories(
        config: [],
        environment: [],
        osFamily: 'Linux',
    );

    expect($directories->documentsPath())->toBeNull();
});
