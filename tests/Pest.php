<?php

use App\Contracts\Trash;
use App\Contracts\UserDirectories;
use App\Exceptions\NoteOperationException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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
