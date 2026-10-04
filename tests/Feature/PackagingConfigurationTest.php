<?php

test('nativephp configuration defines production release identity and offline defaults', function () {
    expect(config('nativephp.version'))->toBe('1.0.0')
        ->and(config('nativephp.app_id'))->toBe('com.mdvault.app')
        ->and(config('nativephp.author'))->toBe('MDVault')
        ->and(config('nativephp.copyright'))->toBe('Copyright © 2026 MDVault')
        ->and(config('nativephp.updater.enabled'))->toBeFalse()
        ->and(config('nativephp.updater.default'))->toBe('github');
});

test('nativephp prebuild hook compiles frontend assets', function () {
    $prebuild = config('nativephp.prebuild', []);

    expect($prebuild)->toContain('npm run build');
});

test('nativephp nsis configuration preserves app data on uninstall', function () {
    expect(config('nativephp.nsis.delete_app_data_on_uninstall'))->toBeFalse();
});

test('nativephp distribution exclusions strip non-production artifacts', function () {
    $exclusions = config('nativephp.cleanup_exclude_files', []);

    expect($exclusions)->toContain('tests')
        ->and($exclusions)->toContain('tests/**')
        ->and($exclusions)->toContain('.ai')
        ->and($exclusions)->toContain('.ai/**')
        ->and($exclusions)->toContain('docs')
        ->and($exclusions)->toContain('docs/**')
        ->and($exclusions)->toContain('phpunit.xml')
        ->and($exclusions)->toContain('phpstan.neon')
        ->and($exclusions)->toContain('pint.json');
});

test('nativephp cleans sensitive and debug environment keys in production builds', function () {
    $envKeys = config('nativephp.cleanup_env_keys', []);

    expect($envKeys)->toContain('APP_DEBUG')
        ->and($envKeys)->toContain('APP_ENV')
        ->and($envKeys)->toContain('*_SECRET');
});
