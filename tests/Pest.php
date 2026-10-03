<?php

use App\Contracts\Trash;
use App\Contracts\UserDirectories;
use App\Exceptions\NoteOperationException;
use App\Models\Vault;
use App\Services\EncryptionService;
use App\Services\SettingsService;
use App\Services\VaultEncryptionService;
use App\Services\VaultKeyService;
use App\Services\VaultService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Bind a fake UserDirectories so storage-related tests never touch the
 * real Documents folder.
 */
function fakeDocumentsDirectory(?string $path): void
{
    app()->instance(UserDirectories::class, new class($path) implements UserDirectories
    {
        public function __construct(private ?string $path) {}

        public function documentsPath(): ?string
        {
            return $this->path;
        }
    });
}

/**
 * Substitute Filesystem so moveDirectory() returns false on the given
 * 1-based call numbers (simulates an OS lock); other calls behave
 * normally. Resolve services AFTER calling this.
 */
function failFolderRenames(array $failOnCalls = [1]): object
{
    $fake = new class($failOnCalls) extends Filesystem
    {
        public int $calls = 0;

        public function __construct(private array $failOnCalls) {}

        public function moveDirectory($from, $to, $overwrite = false): bool
        {
            return in_array(++$this->calls, $this->failOnCalls, true) ? false : parent::moveDirectory($from, $to, $overwrite);
        }
    };
    app()->instance(Filesystem::class, $fake);

    return $fake;
}

/**
 * Substitute Filesystem so move() returns false on the given 1-based call
 * numbers (simulates an OS lock on a file); other calls behave normally.
 * Resolve services AFTER calling this.
 */
function failFileMoves(array $failOnCalls = [1]): object
{
    $fake = new class($failOnCalls) extends Filesystem
    {
        public int $calls = 0;

        public function __construct(private array $failOnCalls) {}

        public function move($path, $target): bool
        {
            return in_array(++$this->calls, $this->failOnCalls, true) ? false : parent::move($path, $target);
        }
    };
    app()->instance(Filesystem::class, $fake);

    return $fake;
}

/**
 * Substitute Filesystem so put() writes only the first $truncateTo bytes
 * (null = normal) and returns false when truncated; $onPut runs before each
 * put (e.g. to simulate an external writer). Resolve services AFTER calling
 * this.
 */
function fakeFilePuts(?int $truncateTo = null, ?Closure $onPut = null): object
{
    $fake = new class($truncateTo, $onPut) extends Filesystem
    {
        public int $calls = 0;

        public function __construct(private ?int $truncateTo, private ?Closure $onPut) {}

        public function put($path, $contents, $lock = false)
        {
            $this->calls++;

            if ($this->onPut !== null) {
                ($this->onPut)();
            }

            if ($this->truncateTo === null) {
                return parent::put($path, $contents, $lock);
            }

            parent::put($path, substr((string) $contents, 0, $this->truncateTo), $lock);

            return false;
        }
    };
    app()->instance(Filesystem::class, $fake);

    return $fake;
}

/**
 * Create files under $root from ['rel/path.md' => 'contents'] (directories
 * created as needed). Keys ending in '/' create empty directories.
 */
function writeVaultFiles(string $root, array $files): void
{
    foreach ($files as $relative => $contents) {
        $native = str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (str_ends_with($relative, '/')) {
            File::makeDirectory(rtrim($root.DIRECTORY_SEPARATOR.$native, DIRECTORY_SEPARATOR), 0755, true, true);

            continue;
        }

        $path = $root.DIRECTORY_SEPARATOR.$native;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
    }
}

/**
 * Runs $callback, expecting it to throw a NoteOperationException, and
 * returns the field it was reported against. Fails the test if it doesn't
 * throw.
 */
function noteOperationField(Closure $callback): string
{
    try {
        $callback();
        test()->fail('Expected a NoteOperationException.');
    } catch (NoteOperationException $e) {
        return $e->field();
    }
}

/**
 * @return list<string>
 */
function zipEntryNames(string $path): array
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::RDONLY);
    $names = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->statIndex($i)['name'];
    }

    $zip->close();

    return $names;
}

function zipEntryContents(string $path, string $name): string
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::RDONLY);
    $contents = $zip->getFromName($name);
    $zip->close();

    return $contents;
}

/**
 * Crafts a ZIP directly with ZipArchive (bypassing ArchiveService), for
 * hostile/invalid backup archives in tests. $entries maps an entry name to
 * its string contents, or null for a directory entry.
 *
 * @param  array<string, string|null>  $entries
 */
function makeZip(string $path, array $entries): void
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($entries as $name => $contents) {
        if ($contents === null) {
            $zip->addEmptyDir(rtrim($name, '/'));
        } else {
            $zip->addFromString($name, $contents);
        }
    }

    $zip->close();
}

/**
 * Simulates "remove local data → fresh application state" (§55): deletes
 * every vault/note/setting/backup row, deletes $oldRoot recursively, points
 * Documents at a NEW temp dir, and forgets scoped services. Returns the new
 * Documents dir.
 */
function simulateFreshInstall(string $oldRoot): string
{
    DB::table('notes')->delete();
    DB::table('vaults')->delete();
    DB::table('settings')->delete();
    DB::table('backups')->delete();

    File::deleteDirectory($oldRoot);

    // AR-05 (analyst review, `mdv-p6`): created under the calling test's own
    // $this->tmp — already recursively deleted by its afterEach — instead
    // of directly under the system temp dir, which leaked a fresh,
    // never-cleaned directory on every call.
    $newDocuments = test()->tmp.DIRECTORY_SEPARATOR.'mdvault-fresh-'.Str::random(8).DIRECTORY_SEPARATOR.'Documents';
    File::makeDirectory($newDocuments, 0755, true);
    fakeDocumentsDirectory($newDocuments);

    app()->forgetInstance(SettingsService::class);

    return $newDocuments;
}

/** Bind a fake Trash. $deletes=true simulates success (deletes the file or directory); false simulates a silent OS failure. */
function fakeTrash(bool $available = true, bool $deletes = true): object
{
    $fake = new class($available, $deletes) implements Trash
    {
        /** @var list<string> */
        public array $trashed = [];

        public function __construct(private bool $available, private bool $deletes) {}

        public function isAvailable(): bool
        {
            return $this->available;
        }

        public function moveToTrash(string $path): void
        {
            $this->trashed[] = $path;
            if ($this->deletes) {
                is_dir($path) ? File::deleteDirectory($path) : File::delete($path);
            }
        }
    };
    app()->instance(Trash::class, $fake);

    return $fake;
}

/**
 * Creates an encrypted vault by hand (key file, mirror, unlocked session)
 * and returns it with its unlock token. The caller must have called
 * fakeDocumentsDirectory() first.
 *
 * @return array{0: Vault, 1: string}
 */
function encryptedVault(string $name, string $password = 'correct horse battery'): array
{
    $vault = app(VaultService::class)->create($name);
    $encryption = app(EncryptionService::class);
    $service = app(VaultEncryptionService::class);

    [$header] = $encryption->newVaultKey($password);
    $bytes = $encryption->encodeHeader($header);
    File::put($vault->path.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME, $bytes);
    $service->adopt($vault, $header, hash('sha256', $bytes));

    $token = $service->unlock($vault->refresh(), $password);

    return [$vault->refresh(), $token];
}

/**
 * Sends the unlock token for $vault with every following request.
 */
function withVaultToken(Illuminate\Foundation\Testing\TestCase $test, Vault $vault, string $token): Illuminate\Foundation\Testing\TestCase
{
    return $test->withHeader(VaultKeyService::HEADER, $vault->uuid.':'.$token);
}

/**
 * Fails if any needle (case-insensitive) occurs in the bytes OR the name of
 * any file or folder under $dir.
 *
 * @param  list<string>  $needles
 */
function assertNoNeedlesUnder(string $dir, array $needles): void
{
    $needles = array_values(array_filter($needles, fn (string $n): bool => $n !== ''));
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        $relative = str_replace($dir, '', $item->getPathname());

        foreach ($needles as $needle) {
            expect(stripos($relative, $needle))->toBeFalse("A name under {$dir} contains a needle.");

            if ($item->isFile()) {
                expect(stripos((string) file_get_contents($item->getPathname()), $needle))->toBeFalse("A file under {$dir} contains a needle.");
            }
        }
    }
}

/**
 * Fails if any needle (case-insensitive) occurs in any row of any table.
 *
 * @param  list<string>  $needles
 */
function assertNoNeedlesInDatabase(array $needles): void
{
    $needles = array_values(array_filter($needles, fn (string $n): bool => $n !== ''));
    $tables = DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'");

    foreach ($tables as $table) {
        foreach (DB::table($table->name)->get() as $row) {
            $json = (string) json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE);

            foreach ($needles as $needle) {
                expect(stripos($json, $needle))->toBeFalse("Table {$table->name} contains a needle.");
            }
        }
    }
}

/**
 * Records every log message (plus its serialized context and any exception
 * message and trace) written from now on. The returned collection fills as
 * the test runs.
 *
 * @return Collection<int, string>
 */
function captureLogs(): Collection
{
    $logs = new Collection;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($logs): void {
        $context = $event->context;
        $exception = $context['exception'] ?? null;
        unset($context['exception']);

        $text = $event->message.' '.json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($exception instanceof Throwable) {
            $text .= ' '.$exception->getMessage().' '.$exception->getTraceAsString();
        }

        $logs->push($text);
    });

    return $logs;
}
