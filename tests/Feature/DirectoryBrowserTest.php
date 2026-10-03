<?php

use Illuminate\Support\Facades\File;

test('browsing directories without path returns default starting location', function () {
    $tempDir = sys_get_temp_dir().'/mdvault_test_'.uniqid();
    File::makeDirectory($tempDir);
    File::makeDirectory($tempDir.'/FolderA');
    File::makeDirectory($tempDir.'/FolderB');

    fakeDocumentsDirectory($tempDir);

    $response = $this->postJson(route('directories.browse'));

    $response->assertOk()
        ->assertJsonStructure([
            'current_path',
            'parent_path',
            'breadcrumbs',
            'drives',
            'quick_links',
            'directories',
            'is_writable',
        ]);

    File::deleteDirectory($tempDir);
});

test('browsing directories with a specific path returns its contents', function () {
    $tempDir = sys_get_temp_dir().'/mdvault_test_'.uniqid();
    File::makeDirectory($tempDir);
    File::makeDirectory($tempDir.'/SubFolder1');
    File::makeDirectory($tempDir.'/SubFolder2');

    $response = $this->postJson(route('directories.browse'), ['path' => $tempDir]);

    $response->assertOk();
    $data = $response->json();

    expect($data['directories'])->toHaveCount(2)
        ->and($data['directories'][0]['name'])->toBe('SubFolder1')
        ->and($data['directories'][1]['name'])->toBe('SubFolder2');

    File::deleteDirectory($tempDir);
});

test('browsing directories with a nonexistent path falls back safely', function () {
    $response = $this->postJson(route('directories.browse'), ['path' => 'C:\\NonExistentPathXYZ\\DoesNotExist']);

    $response->assertOk()
        ->assertJsonStructure(['current_path', 'directories']);
});
