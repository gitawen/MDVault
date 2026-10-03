<?php

use App\Exceptions\VaultLockedException;
use App\Models\Vault;
use Illuminate\Support\Facades\Route;

test('the workspace is served with no-store cache headers', function () {
    $response = $this->get('/');

    $response->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('a failed validation does not flash names, folders, paths, content or passwords', function () {
    $vault = Vault::factory()->create();

    $this->from('/')->post(route('vaults.notes.store', $vault), [
        'name' => 'Bank Accounts<>',
        'folder' => 'My Credentials',
        'content' => 'CANARY-CONTENT-7f3a',
        'password' => 'Canary-Password-91!',
        'token' => 'sometoken',
    ])->assertSessionHasErrors();

    $old = session('_old_input', []);

    expect($old)->not->toHaveKeys(['name', 'folder', 'content', 'password', 'token']);
    expect(serialize(session()->all()))
        ->not->toContain('My Credentials')
        ->not->toContain('CANARY-CONTENT-7f3a')
        ->not->toContain('Canary-Password-91!');
});

test('a locked vault renders 423 JSON for JSON requests and redirects to the workspace otherwise', function () {
    Route::middleware('web')->get('/_test/locked', fn () => throw new VaultLockedException);

    $this->getJson('/_test/locked')
        ->assertStatus(423)
        ->assertExactJson(['message' => 'This vault is locked. Unlock it to continue.', 'reason' => 'locked']);

    $this->get('/_test/locked')->assertRedirect(route('workspace'));
});
