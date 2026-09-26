<?php

test('every settings page renders its component', function (string $routeName, string $component) {
    $response = $this->get(route($routeName));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component($component));
})->with([
    ['settings.general.edit', 'settings/General'],
    ['settings.storage.edit', 'settings/Storage'],
    ['settings.editor.edit', 'settings/Editor'],
    ['settings.appearance.edit', 'settings/Appearance'],
]);
