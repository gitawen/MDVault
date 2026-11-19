<?php

namespace App\Services;

use App\Enums\IndexMode;
use App\Enums\VaultStatus;
use App\Exceptions\McpKosException;
use App\Exceptions\NoteOperationException;
use App\Models\Note;
use App\Models\Vault;

/**
 * Hybrid SQLite metadata + on-demand Markdown content grep search across
 * active, unencrypted vaults.
 */
final class McpSearchService
{
    public const MAX_QUERY_LENGTH = 500;

    public const MAX_SNIPPET_LENGTH = 240;

    public function __construct(
        private readonly VaultService $vaults,
        private readonly VaultIndexService $index,
        private readonly FileStorageService $files,
        private readonly McpKosService $kos,
    ) {}

    /**
     * Searches note titles, relative paths, and Markdown file contents across
     * active unencrypted vaults.
     *
     * @return array{
     *     query: string,
     *     total_matched_notes: int,
     *     truncated: bool,
     *     skipped_encrypted_vault_count: int,
     *     skipped_missing_vault_count: int,
     *     results: list<array<string, mixed>>
     * }
     *
     * @throws McpKosException
     */
    public function search(
        string $query,
        ?string $vaultIdentifier = null,
        ?string $folder = null,
        bool $caseSensitive = false,
        int $limit = 20,
        int $maxMatchesPerNote = 5,
    ): array {
        $needle = trim($query);

        if ($needle === '' || mb_strlen($needle) > self::MAX_QUERY_LENGTH) {
            throw McpKosException::invalidInput('Search query must be between 1 and '.self::MAX_QUERY_LENGTH.' characters.');
        }

        $limit = max(1, min(50, $limit));
        $maxMatchesPerNote = max(1, min(20, $maxMatchesPerNote));
        $normalizedFolder = ($folder !== null && trim($folder) !== '')
            ? $this->kos->normalizeFolderPath(trim($folder), 'folder')
            : '';

        $skippedEncrypted = 0;
        $skippedMissing = 0;

        if ($vaultIdentifier !== null && trim($vaultIdentifier) !== '') {
            $targetVaults = [$this->kos->resolveAccessibleVault($vaultIdentifier)];
        } else {
            $targetVaults = [];

            foreach ($this->vaults->all() as $vault) {
                if ($vault->is_encrypted) {
                    $skippedEncrypted++;

                    continue;
                }

                if ($vault->status === VaultStatus::Missing) {
                    $skippedMissing++;

                    continue;
                }

                $targetVaults[] = $vault;
            }
        }

        if ($targetVaults === []) {
            return [
                'query' => $needle,
                'total_matched_notes' => 0,
                'truncated' => false,
                'skipped_encrypted_vault_count' => $skippedEncrypted,
                'skipped_missing_vault_count' => $skippedMissing,
                'results' => [],
            ];
        }

        /** @var array<int, Vault> $vaultsById */
        $vaultsById = [];

        foreach ($targetVaults as $vault) {
            try {
                $this->index->reconcile($vault, IndexMode::Quick);
            } catch (NoteOperationException $e) {
                throw McpKosException::operationFailed($e->getMessage());
            }

            $vaultsById[$vault->id] = $vault;
        }

        $notesQuery = Note::query()->whereIn('vault_id', array_keys($vaultsById));

        if ($normalizedFolder !== '') {
            $escapedPrefix = $this->escapeLike($normalizedFolder).'/';
            $notesQuery->where('relative_path', 'like', $escapedPrefix.'%');
        }

        $candidates = $notesQuery->get();
        $scored = [];

        foreach ($candidates as $note) {
            $vault = $vaultsById[$note->vault_id] ?? null;

            if ($vault === null) {
                continue;
            }

            $titleMatch = $this->contains($note->title, $needle, $caseSensitive);
            $exactTitle = $caseSensitive
                ? $note->title === $needle
                : mb_strtolower($note->title) === mb_strtolower($needle);
            $pathMatch = $this->contains($note->relative_path, $needle, $caseSensitive);
            $matches = $this->grepNoteContent($vault, $note, $needle, $caseSensitive, $maxMatchesPerNote);

            if (! $titleMatch && ! $pathMatch && $matches === []) {
                continue;
            }

            $score = ($exactTitle ? 1000 : 0)
                + ($titleMatch ? 200 : 0)
                + ($pathMatch ? 100 : 0)
                + count($matches);

            $scored[] = [
                'score' => $score,
                'result' => [
                    'vault' => $vault->name,
                    'vault_uuid' => $vault->uuid,
                    'uuid' => $note->uuid,
                    'title' => $note->title,
                    'relative_path' => $note->relative_path,
                    'folder' => $this->parentFolder($note->relative_path),
                    'file_hash' => $note->file_hash,
                    'file_size' => $note->file_size,
                    'title_match' => $titleMatch,
                    'path_match' => $pathMatch,
                    'match_count' => count($matches),
                    'matches' => $matches,
                    'resource_uri' => 'mdvault://notes/'.rawurlencode($vault->name).'/'.rawurlencode($note->uuid),
                ],
            ];
        }

        usort($scored, function (array $a, array $b): int {
            $scoreCmp = $b['score'] <=> $a['score'];

            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            $vaultCmp = strnatcasecmp((string) $a['result']['vault'], (string) $b['result']['vault']);

            if ($vaultCmp !== 0) {
                return $vaultCmp;
            }

            return strnatcasecmp((string) $a['result']['relative_path'], (string) $b['result']['relative_path']);
        });

        $totalMatched = count($scored);
        $sliced = array_slice($scored, 0, $limit);

        return [
            'query' => $needle,
            'total_matched_notes' => $totalMatched,
            'truncated' => $totalMatched > $limit,
            'skipped_encrypted_vault_count' => $skippedEncrypted,
            'skipped_missing_vault_count' => $skippedMissing,
            'results' => array_map(fn (array $item): array => $item['result'], $sliced),
        ];
    }

    /**
     * @return list<array{line: int, text: string}>
     */
    private function grepNoteContent(
        Vault $vault,
        Note $note,
        string $needle,
        bool $caseSensitive,
        int $maxMatchesPerNote,
    ): array {
        if ($note->file_size > NoteService::PREVIEW_LIMIT) {
            return [];
        }

        $absolute = $this->files->joinRelative($vault->path, $note->relative_path);

        if (! $this->files->isFile($absolute) || $this->files->isSymlink($absolute)) {
            return [];
        }

        $raw = $this->files->read($absolute);

        if ($raw === null || strlen($raw) > NoteService::PREVIEW_LIMIT || ! mb_check_encoding($raw, 'UTF-8')) {
            return [];
        }

        $normalized = str_replace("\r\n", "\n", $raw);
        $lines = explode("\n", $normalized);
        $matches = [];

        foreach ($lines as $index => $line) {
            $pos = $caseSensitive ? mb_strpos($line, $needle) : mb_stripos($line, $needle);

            if ($pos === false) {
                continue;
            }

            $matches[] = [
                'line' => $index + 1,
                'text' => $this->truncateAroundMatch($line, $pos, mb_strlen($needle)),
            ];

            if (count($matches) >= $maxMatchesPerNote) {
                break;
            }
        }

        return $matches;
    }

    private function truncateAroundMatch(string $line, int $matchPos, int $needleLen): string
    {
        $length = mb_strlen($line);

        if ($length <= self::MAX_SNIPPET_LENGTH) {
            return $line;
        }

        $halfContext = intdiv(self::MAX_SNIPPET_LENGTH - min($needleLen, 80), 2);
        $start = max(0, $matchPos - $halfContext);
        $snippet = mb_substr($line, $start, self::MAX_SNIPPET_LENGTH);

        if ($start > 0) {
            $snippet = '...'.mb_substr($snippet, 3);
        }

        if ($start + self::MAX_SNIPPET_LENGTH < $length) {
            $snippet = mb_substr($snippet, 0, self::MAX_SNIPPET_LENGTH - 3).'...';
        }

        return $snippet;
    }

    private function contains(string $haystack, string $needle, bool $caseSensitive): bool
    {
        return $caseSensitive
            ? mb_strpos($haystack, $needle) !== false
            : mb_stripos($haystack, $needle) !== false;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function parentFolder(string $relativePath): string
    {
        $pos = strrpos($relativePath, '/');

        return $pos === false ? '' : substr($relativePath, 0, $pos);
    }
}
