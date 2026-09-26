<?php

test('the desktop runtime baseline configuration has not regressed', function () {
    expect(config('nativephp.app_id'))->toBe('com.mdvault.app')
        ->and(config('nativephp.version'))->toBe('0.1.0')
        ->and(config('nativephp.updater.enabled'))->toBe(false)
        ->and(config('inertia.ssr.enabled'))->toBe(false);
});
