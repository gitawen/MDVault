<?php

use App\Services\FileHashService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-hash-'.Str::random(8);
    mkdir($this->tmp, 0755, true);
    $this->service = app(FileHashService::class);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('an empty file hashes to the known sha-256 of the empty string', function () {
    $path = $this->tmp.DIRECTORY_SEPARATOR.'empty.md';
    file_put_contents($path, '');

    expect($this->service->hashFile($path))->toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
});

test('a file containing "abc" hashes to the known vector', function () {
    $path = $this->tmp.DIRECTORY_SEPARATOR.'abc.md';
    file_put_contents($path, 'abc');

    expect($this->service->hashFile($path))->toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
});

test('hashString gives the same value as hashing the equivalent file', function () {
    expect($this->service->hashString('abc'))->toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
});

test('a missing path gives null', function () {
    expect($this->service->hashFile($this->tmp.DIRECTORY_SEPARATOR.'missing.md'))->toBeNull();
});

test('a directory gives null', function () {
    expect($this->service->hashFile($this->tmp))->toBeNull();
});

test('matches compares the current file hash', function () {
    $path = $this->tmp.DIRECTORY_SEPARATOR.'abc.md';
    file_put_contents($path, 'abc');

    expect($this->service->matches($path, 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad'))->toBeTrue();
    expect($this->service->matches($path, 'wrong'))->toBeFalse();
});

test('a large file is hashed by streaming, matching hash_file directly', function () {
    $path = $this->tmp.DIRECTORY_SEPARATOR.'large.md';
    $handle = fopen($path, 'wb');
    for ($i = 0; $i < 3000; $i++) {
        fwrite($handle, str_repeat('x', 1024));
    }
    fclose($handle);

    expect($this->service->hashFile($path))->toBe(hash_file('sha256', $path));
});
