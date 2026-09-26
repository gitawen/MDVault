<?php

test('settings redirects to appearance', function () {
    $this->get('/settings')->assertRedirect(route('appearance.edit'));
});

test('a guest can view the appearance settings page', function () {
    $response = $this->get(route('appearance.edit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('settings/Appearance'));
});
