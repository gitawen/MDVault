<?php

use App\Exceptions\EncryptionException;
use App\Exceptions\VaultOperationException;
use App\Models\Note;
use App\Models\Vault;
use App\Models\VaultEncryption;
use App\Services\EncryptedNoteService;
use App\Services\EncryptionService;
use App\Services\VaultConversionService;
use App\Services\VaultEncryptionService;
use App\Services\VaultIndexService;
use App\Services\VaultKeyService;
use App\Services\VaultRecoveryService;
use App\Services\VaultService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

const CONV_PASSWORD = 'correct horse battery';
const CONV_CANARY = 'CANARY-CONTENT-7f3a';

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-conv-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

/**
 * A registered, indexed plaintext vault holding $files.
 *
 * @param  array<string, string>  $files
 */
function convPlainVault(array $files, string $name = 'Convert'): Vault
{
    $vault = app(VaultService::class)->create($name);
    writeVaultFiles($vault->path, $files);
    app(VaultIndexService::class)->reindex($vault);

    return $vault->refresh();
}

/**
 * Every note of an unlocked encrypted vault: logical path => plaintext bytes.
 *
 * @return array<string, string>
 */
function convDecryptedNotes(Vault $vault): array
{
    $key = app(VaultKeyService::class)->keyFor($vault, false);
    expect($key)->not->toBeNull();

    $ns = app(EncryptedNoteService::class)->namespace($vault, $key);
    $crypto = app(EncryptionService::class);
    $result = [];

    foreach ($ns->notes as $note) {
        $bytes = File::get($vault->path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $note['disk']));
        $result[$note['logical']] = $crypto->decryptNote($key, $note['file_id'], $bytes)['content'];
    }

    ksort($result);

    return $result;
}

/**
 * A comparable picture of a folder tree: relative path => contents hash.
 *
 * @return array<string, string>
 */
function convTree(string $root): array
{
    $tree = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

    foreach ($iterator as $item) {
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
        $tree[$relative] = $item->isFile() ? hash_file('sha256', $item->getPathname()) : 'dir';
    }

    ksort($tree);

    return $tree;
}

/**
 * @return list<string>
 */
function convLeftovers(Vault $vault): array
{
    // `File::directories()` skips dot folders, which is exactly what is looked for here.
    return array_values(array_filter(
        array_diff(scandir(dirname($vault->path)) ?: [], ['.', '..']),
        fn (string $name): bool => str_starts_with($name, '.mdvault-'),
    ));
}
function convSourceFiles(): array
{
    return [
        'Welcome.md' => "# Welcome\nplain note ".CONV_CANARY."\n",
        'Bank Accounts.md' => "\xEF\xBB\xBF# Bank\r\nline two\r\n".CONV_CANARY."\r\n",
        'Empty.md' => '',
        'My Credentials/Passwords.md' => 'secret '.CONV_CANARY,
        'My Credentials/Deep Folder/Zażółć gęślą.md' => "unicode name\n",
        'Empty Folder/' => '',
        'Binary.md' => "\xff\xfe\x00 not utf-8 ".CONV_CANARY,
    ];
}

test('encrypting a vault keeps UUIDs, preserves every byte and leaves no plaintext behind', function () {
    $vault = convPlainVault(convSourceFiles());
    $uuids = Note::query()->where('vault_id', $vault->id)->pluck('uuid', 'relative_path')->all();
    $expected = [];
    foreach (convSourceFiles() as $path => $content) {
        if (! str_ends_with($path, '/')) {
            $expected[$path] = $content;
        }
    }
    ksort($expected);
    $parent = dirname($vault->path);

    $result = app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD);

    $vault->refresh();

    expect($result->token)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($result->warning)->toBeNull()
        ->and($result->notes)->toBe(6)
        ->and($vault->is_encrypted)->toBeTrue()
        ->and(VaultEncryption::query()->where('vault_id', $vault->id)->count())->toBe(1)
        ->and(convLeftovers($vault))->toBe([])
        ->and(app(VaultKeyService::class)->isUnlocked($vault))->toBeTrue();

    // Same registry rows, new opaque paths.
    $rows = Note::query()->where('vault_id', $vault->id)->get();
    expect($rows)->toHaveCount(6)
        ->and($rows->pluck('uuid')->sort()->values()->all())->toBe(collect($uuids)->values()->sort()->values()->all());

    foreach ($rows as $row) {
        expect($row->is_encrypted)->toBeTrue()
            ->and($row->relative_path)->toMatch('#^([0-9a-f]{32}/)*[0-9a-f]{32}\.mdenc$#')
            ->and($row->mime_type)->toBe(Note::ENCRYPTED_MIME_TYPE);
    }

    // The same notes, byte for byte, under the same logical paths.
    expect(convDecryptedNotes($vault))->toBe($expected);

    // The empty folder survived (as an opaque folder with a name file).
    $names = app(EncryptedNoteService::class)->namespace($vault, app(VaultKeyService::class)->keyFor($vault, false))->folders;
    expect(collect($names)->pluck('logical')->sort()->values()->all())->toBe(['Empty Folder', 'My Credentials', 'My Credentials/Deep Folder']);

    assertNoNeedlesUnder($parent, [CONV_CANARY, 'Bank Accounts', 'My Credentials', 'Passwords', 'Zażółć', 'Empty Folder', 'Welcome', 'Binary']);
    assertNoNeedlesInDatabase([CONV_CANARY, CONV_PASSWORD, 'Bank Accounts', 'My Credentials', 'Passwords', 'Zażółć', 'Empty Folder', 'Welcome', 'Binary', $result->token]);
});

test('a note edited after encryption is still found by the registry and the password unlocks again', function () {
    $vault = convPlainVault(['One.md' => 'one']);

    app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD);
    app(VaultKeyService::class)->forgetAll();

    expect(app(VaultKeyService::class)->isUnlocked($vault->refresh()))->toBeFalse()
        ->and(app(VaultEncryptionService::class)->unlock($vault, CONV_PASSWORD))->toBeString()
        ->and(convDecryptedNotes($vault))->toBe(['One.md' => 'one']);
});

test('encrypting a vault with an unsupported entry is refused and changes nothing', function (array $files, ?Closure $setup) {
    $vault = convPlainVault($files);
    $setup?->call($this, $vault);
    $before = convTree($vault->path);
    $rowsBefore = Note::query()->where('vault_id', $vault->id)->orderBy('relative_path')->pluck('file_hash', 'relative_path')->all();

    try {
        app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD);
        $this->fail('Expected a refusal.');
    } catch (EncryptionException $e) {
        expect($e->problems())->not->toBeEmpty();
    }

    expect(convTree($vault->path))->toBe($before)
        ->and($vault->refresh()->is_encrypted)->toBeFalse()
        ->and(VaultEncryption::query()->count())->toBe(0)
        ->and(convLeftovers($vault))->toBe([])
        ->and(Note::query()->where('vault_id', $vault->id)->orderBy('relative_path')->pluck('file_hash', 'relative_path')->all())->toBe($rowsBefore);
})->with([
    'an attachment' => [['a.md' => 'x', 'photo.png' => 'png'], null],
    'a .git folder' => [['a.md' => 'x', '.git/HEAD' => 'ref'], null],
    'a .obsidian folder' => [['a.md' => 'x', '.obsidian/app.json' => '{}'], null],
    'a node_modules folder' => [['a.md' => 'x', 'node_modules/pkg/readme.md' => 'x'], null],
    'a hidden file' => [['a.md' => 'x', '.hidden.md' => 'x'], null],
    'a fresh save temp file' => [['a.md' => 'x', '.mdvault-save-abc123' => 'x'], null],
    'an oversized note' => [['a.md' => 'x', 'big.md' => str_repeat('a', 16 * 1024 * 1024 + 1)], null],
]);

test('a symlink inside the vault is refused', function () {
    $vault = convPlainVault(['a.md' => 'x']);
    $target = $this->tmp.DIRECTORY_SEPARATOR.'elsewhere';
    File::makeDirectory($target);
    symlink($target, $vault->path.DIRECTORY_SEPARATOR.'link');

    expect(fn () => app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD))->toThrow(EncryptionException::class);
    expect(File::isDirectory($target))->toBeTrue()
        ->and(is_link($vault->path.DIRECTORY_SEPARATOR.'link'))->toBeTrue()
        ->and($vault->refresh()->is_encrypted)->toBeFalse();
})->skipOnWindows();

test('an old save temp file is discarded with the original', function () {
    $vault = convPlainVault(['a.md' => 'x']);
    $temp = $vault->path.DIRECTORY_SEPARATOR.'.mdvault-save-old123';
    File::put($temp, 'stale');
    touch($temp, time() - 3600);

    app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD);

    expect(convDecryptedNotes($vault->refresh()))->toBe(['a.md' => 'x'])
        ->and(File::exists($vault->path.DIRECTORY_SEPARATOR.'.mdvault-save-old123'))->toBeFalse();
});

test('a note changed while the encrypted copy is being built aborts the conversion', function () {
    $vault = convPlainVault(['a.md' => 'original', 'b.md' => 'second']);
    $before = convTree($vault->path);

    // The first directory created inside the staging folder is the first moment after the scan.
    $note = $vault->path.DIRECTORY_SEPARATOR.'a.md';
    $fake = new class($note) extends Filesystem
    {
        public function __construct(private string $note) {}

        public function makeDirectory($path, $mode = 0755, $recursive = false, $force = false): bool
        {
            if (str_contains($path, '.mdvault-encrypt-')) {
                file_put_contents($this->note, 'changed behind our back');
            }

            return parent::makeDirectory($path, $mode, $recursive, $force);
        }
    };
    app()->instance(Filesystem::class, $fake);

    expect(fn () => app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD))
        ->toThrow(EncryptionException::class, 'changed while it was being converted');

    expect(File::get($note))->toBe('changed behind our back')
        ->and(File::get($vault->path.DIRECTORY_SEPARATOR.'b.md'))->toBe('second')
        ->and($vault->refresh()->is_encrypted)->toBeFalse()
        ->and(convLeftovers($vault))->toBe([])
        ->and(VaultEncryption::query()->count())->toBe(0);
});

test('a note added after the scan is never deleted with the original', function () {
    $vault = convPlainVault(['a.md' => 'original']);
    $late = $vault->path.DIRECTORY_SEPARATOR.'Late.md';

    // `makeDirectory` of the staging folder runs after the pre-staging snapshot.
    $fake = new class($late) extends Filesystem
    {
        public function __construct(private string $late) {}

        public function makeDirectory($path, $mode = 0755, $recursive = false, $force = false): bool
        {
            if (str_contains($path, '.mdvault-encrypt-')) {
                file_put_contents($this->late, 'written during conversion');
            }

            return parent::makeDirectory($path, $mode, $recursive, $force);
        }
    };
    app()->instance(Filesystem::class, $fake);

    expect(fn () => app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD))->toThrow(EncryptionException::class);

    expect(File::get($late))->toBe('written during conversion')
        ->and($vault->refresh()->is_encrypted)->toBeFalse()
        ->and(convLeftovers($vault))->toBe([]);
});

test('a failed swap leaves the original intact and the registry unchanged', function (array $failOnCalls) {
    $vault = convPlainVault(convSourceFiles());
    $before = convTree($vault->path);
    $rowsBefore = Note::query()->where('vault_id', $vault->id)->orderBy('relative_path')->pluck('file_hash', 'relative_path')->all();

    failFolderRenames($failOnCalls);

    expect(fn () => app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD))
        ->toThrow(EncryptionException::class, 'Close any programs');

    expect(convTree($vault->path))->toBe($before)
        ->and($vault->refresh()->is_encrypted)->toBeFalse()
        ->and(VaultEncryption::query()->count())->toBe(0)
        ->and(convLeftovers($vault))->toBe([])
        ->and(Note::query()->where('vault_id', $vault->id)->orderBy('relative_path')->pluck('file_hash', 'relative_path')->all())->toBe($rowsBefore)
        ->and(Note::query()->where('is_encrypted', true)->count())->toBe(0);
})->with([
    'the first rename fails' => [[1]],
    'the second rename fails' => [[2]],
]);

test('when the rename back also fails the original is restored by recovery', function () {
    $vault = convPlainVault(['a.md' => 'keep me']);

    failFolderRenames([2, 3]);

    expect(fn () => app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD))->toThrow(EncryptionException::class);

    // The folder is parked as the original; recovery puts it back.
    expect(File::isDirectory($vault->path))->toBeFalse();

    expect(app(VaultRecoveryService::class)->recover($vault->refresh()))->toBe('restored-original');
    expect(File::get($vault->path.DIRECTORY_SEPARATOR.'a.md'))->toBe('keep me')
        ->and(convLeftovers($vault))->toBe([])
        ->and($vault->refresh()->is_encrypted)->toBeFalse();
});

test('a database failure while recording the conversion leaves the original intact', function (string $trigger) {
    $vault = convPlainVault(convSourceFiles());
    $before = convTree($vault->path);

    DB::unprepared($trigger);

    try {
        expect(fn () => app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD))
            ->toThrow(EncryptionException::class, "couldn't be converted");
    } finally {
        // no cleanup needed
        DB::unprepared('drop trigger if exists fail_conv');
    }

    expect(convTree($vault->path))->toBe($before)
        ->and($vault->refresh()->is_encrypted)->toBeFalse()
        ->and(VaultEncryption::query()->count())->toBe(0)
        ->and(Note::query()->where('is_encrypted', true)->count())->toBe(0)
        ->and(convLeftovers($vault))->toBe([]);
})->with([
    'a notes update fails' => ['create trigger fail_conv before update on notes begin select raise(abort, "boom"); end'],
    'the mirror insert fails' => ['create trigger fail_conv before insert on vault_encryption begin select raise(abort, "boom"); end'],
]);

test('a failed cleanup is reported as a warning and a later recovery deletes the original', function () {
    $vault = convPlainVault(['a.md' => 'secret '.CONV_CANARY]);

    $fake = new class extends Filesystem
    {
        public function deleteDirectory($directory, $preserve = false): bool
        {
            return str_contains($directory, '.mdvault-original-') ? false : parent::deleteDirectory($directory, $preserve);
        }
    };
    app()->instance(Filesystem::class, $fake);

    $result = app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD);

    expect($result->warning)->toContain('leftover folder')
        ->and($vault->refresh()->is_encrypted)->toBeTrue()
        ->and(convLeftovers($vault))->toHaveCount(1);

    app()->instance(Filesystem::class, new Filesystem);

    expect(app(VaultRecoveryService::class)->recover($vault))->toBe('removed-original')
        ->and(convLeftovers($vault))->toBe([]);

    assertNoNeedlesUnder(dirname($vault->path), [CONV_CANARY]);
});

test('encrypting an already encrypted vault or a missing one is refused', function () {
    [$encrypted] = encryptedVault('Already');

    expect(fn () => app(VaultConversionService::class)->encrypt($encrypted, CONV_PASSWORD))
        ->toThrow(EncryptionException::class, 'already encrypted');

    $missing = convPlainVault(['a.md' => 'x'], 'Gone');
    File::deleteDirectory($missing->path);

    expect(fn () => app(VaultConversionService::class)->encrypt($missing, CONV_PASSWORD))
        ->toThrow(VaultOperationException::class);
});

test('a conversion in flight blocks a second one and recovery', function () {
    $vault = convPlainVault(['a.md' => 'x']);
    $release = app(VaultRecoveryService::class)->lock($vault);

    expect($release)->not->toBeNull();

    try {
        // On a store without locks the marker is a no-op, so only assert when it is held.
        $second = app(VaultRecoveryService::class)->lock($vault);

        if ($second === null) {
            expect(fn () => app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD))->toThrow(EncryptionException::class);
            expect($vault->refresh()->is_encrypted)->toBeFalse();
        } else {
            $second();
        }
    } finally {
        // no cleanup needed
        $release();
    }
});

test('removing encryption restores the original files and removes the key material', function () {
    $vault = convPlainVault(convSourceFiles());
    $uuids = Note::query()->where('vault_id', $vault->id)->pluck('uuid', 'relative_path')->all();
    $treeBefore = convTree($vault->path);

    app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD);
    $vault->refresh();

    $result = app(VaultConversionService::class)->decrypt($vault, CONV_PASSWORD);
    $vault->refresh();

    expect($result->token)->toBeNull()
        ->and($result->warning)->toBeNull()
        ->and($result->notes)->toBe(6)
        ->and($vault->is_encrypted)->toBeFalse()
        ->and(VaultEncryption::query()->where('vault_id', $vault->id)->count())->toBe(0)
        ->and(convLeftovers($vault))->toBe([])
        ->and(File::exists($vault->path.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME))->toBeFalse()
        ->and(app(VaultKeyService::class)->isUnlocked($vault))->toBeFalse()
        ->and(convTree($vault->path))->toBe($treeBefore);

    // Same UUIDs, readable paths again.
    expect(Note::query()->where('vault_id', $vault->id)->pluck('uuid', 'relative_path')->all())->toBe($uuids);

    foreach (Note::query()->where('vault_id', $vault->id)->get() as $row) {
        expect($row->is_encrypted)->toBeFalse()->and($row->mime_type)->toBe(Note::MIME_TYPE);
    }
});

test('removing encryption suffixes duplicate and invalid names', function () {
    [$vault, $token] = encryptedVault('Dups');
    $key = app(VaultKeyService::class)->keyFor($vault, false);
    $crypto = app(EncryptionService::class);

    foreach (['Same' => 'one', 'same' => 'two', 'a:b' => 'three', 'a:b ' => 'four'] as $name => $content) {
        $id = $crypto->newFileId();
        File::put($vault->path.DIRECTORY_SEPARATOR.$id.'.mdenc', $crypto->encryptNote($key, $id, $name, $content));
    }

    // A folder whose name duplicates another folder's name case-insensitively.
    foreach (['Docs', 'docs'] as $folderName) {
        $folderId = $crypto->newFileId();
        File::makeDirectory($vault->path.DIRECTORY_SEPARATOR.$folderId);
        File::put($vault->path.DIRECTORY_SEPARATOR.$folderId.DIRECTORY_SEPARATOR.'folder.mdenc', $crypto->encryptFolderName($key, $folderId, $folderName));
    }

    app(VaultIndexService::class)->reindex($vault);

    app(VaultConversionService::class)->decrypt($vault->refresh(), CONV_PASSWORD);

    $names = collect(File::files($vault->path))->map(fn (SplFileInfo $f): string => $f->getFilename())->sort()->values()->all();
    $folders = collect(File::directories($vault->path))->map('basename')->sort()->values()->all();

    $lower = array_map('mb_strtolower', $names);
    $same = array_values(array_filter($lower, fn (string $n): bool => str_starts_with($n, 'same')));
    sort($same);

    expect($names)->toHaveCount(4)
        ->and(count(array_unique($lower)))->toBe(4)
        ->and($same)->toBe(['same (2).md', 'same.md'])
        ->and(array_values(array_filter($names, fn (string $n): bool => str_starts_with($n, 'Untitled'))))->toHaveCount(2)
        ->and(count(array_unique(array_map('mb_strtolower', $folders))))->toBe(2)
        ->and(collect($folders)->contains(fn (string $n): bool => str_contains($n, '(2)')))->toBeTrue();
});

test('removing encryption with the wrong password changes nothing', function () {
    $vault = convPlainVault(['a.md' => 'x']);
    app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD);
    $vault->refresh();
    $before = convTree($vault->path);

    expect(fn () => app(VaultConversionService::class)->decrypt($vault, 'definitely wrong pass'))
        ->toThrow(EncryptionException::class, "didn't unlock");

    expect(convTree($vault->path))->toBe($before)
        ->and($vault->refresh()->is_encrypted)->toBeTrue()
        ->and(convLeftovers($vault))->toBe([]);
});

test('removing encryption refuses a foreign plaintext file and a tampered note', function () {
    $vault = convPlainVault(['a.md' => 'x', 'b.md' => 'y']);
    app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD);
    $vault->refresh();

    File::put($vault->path.DIRECTORY_SEPARATOR.'Secret.md', 'plain');
    $before = convTree($vault->path);

    expect(fn () => app(VaultConversionService::class)->decrypt($vault, CONV_PASSWORD))
        ->toThrow(EncryptionException::class, "aren't MDVault notes");
    expect(convTree($vault->path))->toBe($before);

    File::delete($vault->path.DIRECTORY_SEPARATOR.'Secret.md');

    $victim = Note::query()->where('vault_id', $vault->id)->first();
    $file = $vault->path.DIRECTORY_SEPARATOR.$victim->relative_path;
    $bytes = File::get($file);
    File::put($file, substr($bytes, 0, -3).'xyz');
    $before = convTree($vault->path);

    expect(fn () => app(VaultConversionService::class)->decrypt($vault, CONV_PASSWORD))
        ->toThrow(EncryptionException::class, "couldn't be decrypted");
    expect(convTree($vault->path))->toBe($before)
        ->and($vault->refresh()->is_encrypted)->toBeTrue()
        ->and(convLeftovers($vault))->toBe([]);
});

test('a database failure while removing encryption leaves the encrypted vault intact', function () {
    $vault = convPlainVault(['a.md' => 'x']);
    app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD);
    $vault->refresh();
    $before = convTree($vault->path);

    DB::unprepared('create trigger fail_conv before delete on vault_encryption begin select raise(abort, "boom"); end');

    try {
        expect(fn () => app(VaultConversionService::class)->decrypt($vault, CONV_PASSWORD))->toThrow(EncryptionException::class);
    } finally {
        // no cleanup needed
        DB::unprepared('drop trigger if exists fail_conv');
    }

    expect(convTree($vault->path))->toBe($before)
        ->and($vault->refresh()->is_encrypted)->toBeTrue()
        ->and(VaultEncryption::query()->count())->toBe(1)
        ->and(convLeftovers($vault))->toBe([]);
});

test('conversion failures never put names, content or the password into the logs', function () {
    $logs = captureLogs();
    $vault = convPlainVault(['Bank Accounts.md' => CONV_CANARY]);

    DB::unprepared('create trigger fail_conv before update on notes begin select raise(abort, "boom"); end');

    try {
        expect(fn () => app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD))->toThrow(EncryptionException::class);
    } finally {
        // no cleanup needed
        DB::unprepared('drop trigger if exists fail_conv');
    }

    failFolderRenames([1]);
    expect(fn () => app(VaultConversionService::class)->encrypt($vault->refresh(), CONV_PASSWORD))->toThrow(EncryptionException::class);

    $joined = $logs->implode("\n");

    foreach ([CONV_CANARY, 'Bank Accounts', CONV_PASSWORD] as $needle) {
        expect(stripos($joined, $needle))->toBeFalse();
    }
});

/**
 * A connection whose transaction runs the callback and then fails to commit
 * (rolling the DB writes back), after the folder swap happened.
 */
function failCommitAfterCallback(Vault $vault): void
{
    $real = DB::connection();
    $original = app(VaultRecoveryService::class)->originalPath($vault);

    // Passes everything through, except the transaction that swapped the
    // folders: its DB writes are rolled back and the commit "fails".
    $stub = new class($real, $original)
    {
        public function __construct(private $real, private string $original) {}

        public function transaction(Closure $callback, $attempts = 1)
        {
            $this->real->beginTransaction();

            try {
                $result = $callback($this->real);
            } catch (Throwable $e) {
                $this->real->rollBack();

                throw $e;
            }

            if (is_dir($this->original)) {
                $this->real->rollBack();

                throw new RuntimeException('commit failed');
            }

            $this->real->commit();

            return $result;
        }

        public function __call($method, $arguments)
        {
            return $this->real->{$method}(...$arguments);
        }
    };

    $manager = new class(app(), app('db.factory'), $stub) extends DatabaseManager
    {
        public function __construct($app, $factory, private $stub)
        {
            parent::__construct($app, $factory);
        }

        public function connection($name = null)
        {
            return $this->stub;
        }
    };

    app()->instance(DatabaseManager::class, $manager);
    app()->forgetInstance(VaultConversionService::class);
}

test('a commit failure after the swap undoes the swap', function () {
    $vault = convPlainVault(['a.md' => 'keep me']);
    $before = convTree($vault->path);

    failCommitAfterCallback($vault);

    expect(fn () => app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD))
        ->toThrow(EncryptionException::class, "couldn't be converted");

    expect(convTree($vault->path))->toBe($before)
        ->and($vault->refresh()->is_encrypted)->toBeFalse()
        ->and(VaultEncryption::query()->count())->toBe(0)
        ->and(convLeftovers($vault))->toBe([]);
});

test('when undoing the swap also fails recovery rolls it back', function () {
    $vault = convPlainVault(['a.md' => 'keep me']);

    $fake = failFolderRenames([4]);
    failCommitAfterCallback($vault);

    expect(fn () => app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD))->toThrow(EncryptionException::class);
    expect($fake->calls)->toBeGreaterThanOrEqual(4);

    // The converted folder was put back over an unencrypted registry: a mismatch.
    expect($vault->refresh()->is_encrypted)->toBeFalse()
        ->and(File::exists($vault->path.DIRECTORY_SEPARATOR.'mdvault-encryption.json'))->toBeTrue();

    expect(app(VaultRecoveryService::class)->recover($vault))->toBe('rolled-back');
    expect(File::get($vault->path.DIRECTORY_SEPARATOR.'a.md'))->toBe('keep me')
        ->and(convLeftovers($vault))->toBe([])
        ->and($vault->refresh()->is_encrypted)->toBeFalse();
});

test('a conversion that lost its lock aborts without a registry or folder mismatch', function () {
    config(['cache.default' => 'database']);

    $vault = convPlainVault(['a.md' => 'keep me']);
    $before = convTree($vault->path);
    $lockName = VaultRecoveryService::LOCK_PREFIX.$vault->uuid;

    // The lock vanishes (cache clear, expiry) after the conversion started, and
    // recovery then takes it, just before the swap.
    afterNotesUpdate(function () use ($lockName): void {
        app('cache')->store()->getStore()->lock($lockName, 1)->forceRelease();
    });

    try {
        expect(fn () => app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD))
            ->toThrow(EncryptionException::class, "couldn't be converted");
    } finally {
        // no cleanup needed
    }

    expect(convTree($vault->path))->toBe($before)
        ->and($vault->refresh()->is_encrypted)->toBeFalse()
        ->and(VaultEncryption::query()->count())->toBe(0)
        ->and(Note::query()->where('is_encrypted', true)->count())->toBe(0)
        ->and(convLeftovers($vault))->toBe([]);
});

test('a write that lands just before the swap aborts the conversion', function () {
    $vault = convPlainVault(['a.md' => 'keep me']);

    afterNotesUpdate(function () use ($vault): void {
        File::put($vault->path.DIRECTORY_SEPARATOR.'late.md', 'written late');
    });

    try {
        expect(fn () => app(VaultConversionService::class)->encrypt($vault, CONV_PASSWORD))
            ->toThrow(EncryptionException::class);
    } finally {
        // no cleanup needed
    }

    expect(File::get($vault->path.DIRECTORY_SEPARATOR.'late.md'))->toBe('written late')
        ->and(File::get($vault->path.DIRECTORY_SEPARATOR.'a.md'))->toBe('keep me')
        ->and($vault->refresh()->is_encrypted)->toBeFalse()
        ->and(convLeftovers($vault))->toBe([]);
});

/**
 * Runs $callback once, right after the first `update notes` query (the
 * conversion's DB writes run just before the guard and the swap).
 */
function afterNotesUpdate(Closure $callback): void
{
    $done = false;

    DB::listen(function ($query) use ($callback, &$done): void {
        if (! $done && str_starts_with($query->sql, 'update "notes"')) {
            $done = true;
            $callback();
        }
    });
}
