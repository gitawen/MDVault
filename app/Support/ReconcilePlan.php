<?php

namespace App\Support;

/**
 * An immutable, already-computed reconcile plan (ADR
 * `external-change-reconciliation`): everything `VaultIndexService::apply()`
 * needs to write, plus the fingerprint snapshot it re-checks (inside the
 * transaction) before writing anything, aborting as `stale` on any
 * difference. Rows are referenced by id only (never a hydrated `Note`), so
 * `apply()` always writes through models it fetches fresh inside its own
 * transaction.
 */
final readonly class ReconcilePlan
{
    /**
     * @param  array<int, string>  $snapshot  note id => "relative_path\0file_hash\0file_size" for every vault row at plan time
     * @param  list<array{id: int, uuid: string, path: string}>  $deletes
     * @param  list<array{id: int, attributes: array<string, mixed>}>  $updates
     * @param  list<array{id: int, attributes: array<string, mixed>, from: string, content_changed: bool}>  $moves
     * @param  list<array{id: int, file_mtime: ?int}>  $touches
     * @param  list<array<string, mixed>>  $inserts
     * @param  list<string>  $directories
     * @param  list<string>  $orphans  vault-relative paths of `.mdvault-save-*` files older than the orphan threshold
     */
    public function __construct(
        public array $snapshot,
        public array $deletes,
        public array $updates,
        public array $moves,
        public array $touches,
        public array $inserts,
        public int $unchanged,
        public int $skipped,
        public array $directories,
        public array $orphans,
    ) {}
}
