<?php

use App\Models\Vault;
use App\Services\MediaService;
use App\Services\StoragePathService;
use App\Services\VaultService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-media-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

/**
 * Create a tiny valid raster image UploadedFile using GD (supports png/jpg/gif/webp).
 */
function makeRasterUpload(string $name, string $format): UploadedFile
{
    $img = imagecreatetruecolor(4, 4);
    expect($img)->not->toBeFalse();

    $color = imagecolorallocate($img, 24, 120, 200);
    imagefilledrectangle($img, 0, 0, 3, 3, $color);

    $tmpPath = tempnam(sys_get_temp_dir(), 'mdv-img-');
    expect($tmpPath)->not->toBeFalse();

    $mime = match ($format) {
        'png' => (function () use ($img, $tmpPath): string {
            imagepng($img, $tmpPath);

            return 'image/png';
        })(),
        'jpg' => (function () use ($img, $tmpPath): string {
            imagejpeg($img, $tmpPath);

            return 'image/jpeg';
        })(),
        'gif' => (function () use ($img, $tmpPath): string {
            imagegif($img, $tmpPath);

            return 'image/gif';
        })(),
        'webp' => (function () use ($img, $tmpPath): string {
            imagewebp($img, $tmpPath);

            return 'image/webp';
        })(),
        default => throw new InvalidArgumentException("Unsupported format: {$format}"),
    };

    imagedestroy($img);

    return new UploadedFile($tmpPath, $name, $mime, test: true);
}

test('uploading valid raster images lazily creates Media folder and returns safe metadata', function (string $filename, string $format, string $expectedExt, string $expectedMime) {
    $vault = app(VaultService::class)->create('Work Notes');
    $mediaDir = app(StoragePathService::class)->mediaDirectory();

    expect(is_dir($mediaDir))->toBeFalse();

    $upload = makeRasterUpload($filename, $format);

    $response = $this->postJson(route('vaults.media.store', $vault->uuid), [
        'image' => $upload,
    ]);

    $response->assertCreated();

    $data = $response->json();

    expect(array_keys($data))->toEqualCanonicalizing(['filename', 'url', 'mime_type', 'size'])
        ->and($data['filename'])->toStartWith('Work-Notes-')
        ->and($data['filename'])->toEndWith(".{$expectedExt}")
        ->and($data['filename'])->toMatch('/\A[A-Za-z0-9._-]+\z/')
        ->and($data['url'])->toBe('/media/'.$data['filename'])
        ->and($data['mime_type'])->toBe($expectedMime)
        ->and($data['size'])->toBeGreaterThan(0)
        ->and(str_contains(json_encode($data), $this->tmp))->toBeFalse()
        ->and(is_dir($mediaDir))->toBeTrue()
        ->and(is_file($mediaDir.DIRECTORY_SEPARATOR.$data['filename']))->toBeTrue();
})->with([
    'png' => ['diagram.png', 'png', 'png', 'image/png'],
    'jpeg' => ['photo.jpeg', 'jpg', 'jpg', 'image/jpeg'],
    'gif' => ['anim.gif', 'gif', 'gif', 'image/gif'],
    'webp' => ['shot.webp', 'webp', 'webp', 'image/webp'],
]);

test('sanitizes vault and file names and never overwrites existing attachments', function () {
    $vault = app(VaultService::class)->create('Café & Work!');
    $mediaDir = app(StoragePathService::class)->mediaDirectory();

    $first = $this->postJson(route('vaults.media.store', $vault->uuid), [
        'image' => makeRasterUpload('Architecture Diagram (v2).png', 'png'),
    ])->assertCreated()->json();

    $second = $this->postJson(route('vaults.media.store', $vault->uuid), [
        'image' => makeRasterUpload('Architecture Diagram (v2).png', 'png'),
    ])->assertCreated()->json();

    expect($first['filename'])->toStartWith('Cafe-Work-Architecture-Diagram-v2-')
        ->and($second['filename'])->not->toBe($first['filename'])
        ->and(count(File::files($mediaDir)))->toBe(2);
});

test('rejects invalid, SVG, disguised HTML, corrupt, and oversized uploads cleanly', function () {
    $vault = app(VaultService::class)->create('Work');
    $mediaDir = app(StoragePathService::class)->mediaDirectory();

    // Missing image field
    $this->postJson(route('vaults.media.store', $vault->uuid), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');

    // SVG upload
    $svg = UploadedFile::fake()->createWithContent(
        'vector.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    );
    $this->postJson(route('vaults.media.store', $vault->uuid), ['image' => $svg])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');

    // HTML disguised as PNG
    $htmlAsPng = UploadedFile::fake()->createWithContent(
        'fake.png',
        '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>',
    );
    $this->postJson(route('vaults.media.store', $vault->uuid), ['image' => $htmlAsPng])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');

    // Corrupt binary disguised as PNG
    $corrupt = UploadedFile::fake()->createWithContent('corrupt.png', random_bytes(64));
    $this->postJson(route('vaults.media.store', $vault->uuid), ['image' => $corrupt])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');

    // Oversized (> 10 MB)
    $tooLarge = UploadedFile::fake()->create('huge.png', 10241, 'image/png');
    $this->postJson(route('vaults.media.store', $vault->uuid), ['image' => $tooLarge])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');

    expect(is_dir($mediaDir))->toBeFalse();
});

test('rejects uploads for missing vaults, encrypted vaults (locked or unlocked), and Media-overlapping vaults', function () {
    $mediaDir = app(StoragePathService::class)->mediaDirectory();

    // Missing vault on disk
    $missing = app(VaultService::class)->create('MissingVault');
    File::deleteDirectory($missing->path);

    $this->postJson(route('vaults.media.store', $missing->uuid), [
        'image' => makeRasterUpload('shot.png', 'png'),
    ])->assertUnprocessable()->assertJsonValidationErrors('image');

    // Encrypted vault (unlocked & locked)
    [$encrypted, $token] = encryptedVault('Secrets', 'correct horse battery');

    withVaultToken($this, $encrypted, $token)
        ->postJson(route('vaults.media.store', $encrypted->uuid), [
            'image' => makeRasterUpload('secret.png', 'png'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');

    $this->postJson(route('vaults.media.store', $encrypted->uuid), [
        'image' => makeRasterUpload('secret.png', 'png'),
    ])->assertUnprocessable()->assertJsonValidationErrors('image');

    // Pre-existing vault record overlapping Media/
    $work = app(VaultService::class)->create('Work');
    File::ensureDirectoryExists($mediaDir);
    Vault::query()->create([
        'name' => 'LegacyMedia',
        'path' => realpath($mediaDir) ?: $mediaDir,
        'relative_path' => 'Media',
        'status' => 'active',
        'is_encrypted' => false,
    ]);

    $this->postJson(route('vaults.media.store', $work->uuid), [
        'image' => makeRasterUpload('shot.png', 'png'),
    ])->assertUnprocessable()->assertJsonValidationErrors('image');

    expect(File::files($mediaDir))->toBe([]);
});

test('serves stored images inline with nosniff and rejects unsafe or missing paths with 404', function () {
    $vault = app(VaultService::class)->create('Work');
    $mediaDir = app(StoragePathService::class)->mediaDirectory();

    $stored = $this->postJson(route('vaults.media.store', $vault->uuid), [
        'image' => makeRasterUpload('chart.png', 'png'),
    ])->assertCreated()->json();

    $showResponse = $this->get($stored['url']);
    $showResponse->assertOk();
    expect($showResponse->headers->get('Content-Type'))->toStartWith('image/png')
        ->and($showResponse->headers->get('Content-Disposition'))->toBe('inline; filename="'.$stored['filename'].'"')
        ->and($showResponse->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    // Missing file
    $this->get(route('media.show', ['filename' => 'does-not-exist.png']))->assertNotFound();

    // Path traversal
    $this->get('/media/..%2Fsecret.png')->assertNotFound();
    $this->get('/media/..')->assertNotFound();

    // Dotfile manually placed in Media/
    File::put($mediaDir.DIRECTORY_SEPARATOR.'.hidden.png', File::get($mediaDir.DIRECTORY_SEPARATOR.$stored['filename']));
    $this->get('/media/.hidden.png')->assertNotFound();

    // SVG / disguised HTML manually dropped in Media/
    File::put($mediaDir.DIRECTORY_SEPARATOR.'evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    File::put($mediaDir.DIRECTORY_SEPARATOR.'disguised.png', '<html><body>xss</body></html>');
    $this->get(route('media.show', ['filename' => 'evil.svg']))->assertNotFound();
    $this->get(route('media.show', ['filename' => 'disguised.png']))->assertNotFound();

    // Symlink in Media/ (best-effort on OSes that allow unprivileged symlinks)
    $outside = $this->tmp.DIRECTORY_SEPARATOR.'outside.png';
    File::copy($mediaDir.DIRECTORY_SEPARATOR.$stored['filename'], $outside);
    $symlinkPath = $mediaDir.DIRECTORY_SEPARATOR.'linked.png';

    if (@symlink($outside, $symlinkPath)) {
        expect(app(MediaService::class)->resolveForServing('linked.png'))->toBeNull();
        $this->get(route('media.show', ['filename' => 'linked.png']))->assertNotFound();
    }
});
