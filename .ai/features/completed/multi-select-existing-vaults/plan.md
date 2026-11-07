# Plan: Multi-Select Existing Vaults

## Metadata
- **Feature Name**: Multi-Select Existing Vaults
- **Feature ID**: feat-multi-select-existing-vaults
- **Author**: System Analyst
- **Created Date**: 2026-11-07
- **Task Complexity**: Level 3 — Complex Development
- **Requirements**: `requirements.md`
- **Status**: APPROVED

---

## 1. Summary
Enable users to select and register multiple existing vault directories at once. We will enhance `DirectoryBrowserDialog.vue` with an optional `multiple` prop that renders checkboxes, supports row selection, preserves selections during directory traversal, provides "Select all in folder", and falls back to selecting the current folder if 0 checkboxes are checked. We will upgrade `AddExistingVaultDialog.vue` to display a batch review view for multiple folders and implement a dedicated backend endpoint `POST /vaults/existing/batch` that registers vaults using best-effort execution, reconciles note indexes, sets the first vault active, and provides a informative flash toast summary.

---

## 2. Architecture & Design

- **Approach**:
  - **Component Reusability**: Extend `DirectoryBrowserDialog.vue` with `multiple?: boolean` (defaults to `false`). When `false`, existing single-selection consumers (such as `Storage.vue`) remain completely unaffected.
  - **Interaction Clarity**: In multi-select mode, each folder item displays a checkbox. Clicking anywhere on the item card (or checkbox) toggles its selection. Clicking the `>` chevron button explicitly opens the folder without selecting, allowing deep folder traversal. Double-clicking also opens the folder.
  - **Selection State Persistence**: Selected paths are stored in an array/set (`selectedPaths`) that persists as the user navigates across directory levels, with "Select all in folder" and "Clear" helpers.
  - **Fallback Semantics**: If no checkboxes are checked, the target path defaults to `currentPath` and the confirmation button submits `[currentPath]`.
  - **Two-Mode Dialog UX**: `AddExistingVaultDialog.vue` dynamically switches between `single` mode (custom name & description form) and `batch` mode (selected folder card list with individual remove controls and total count).
  - **Dedicated Batch Endpoint**: Create `POST /vaults/existing/batch` handled by `ExistingVaultController::batchStore()`. This keeps single-vault registration (`POST /vaults/existing`) strictly focused on single vaults with custom names, while batch registration operates on `paths: string[]`.
  - **Best-Effort Registration & Rich Feedback**: Iteratively registers each vault via `VaultService::register()` and indexes notes via `VaultIndexService::reconcile()`. If individual vaults fail validation (e.g. duplicates or overlaps), they are tracked as `$skipped` rather than failing the entire batch. If all fail, a `ValidationException` is thrown.
- **Alternatives Considered**:
  - *Combine single and batch into `POST /vaults/existing`*: Rejected because it complicates `RegisterVaultRequest` validation rules, mixes single-name override logic with array-of-paths logic, and weakens test isolation.
  - *Strict atomic rollback (all-or-nothing)*: Rejected because if a user selects 10 folders and 1 happens to already exist, failing all 9 valid folders causes poor UX and user frustration.
- **Decision Records**: `.ai/decisions/batch-vault-registration.md`

### Data Model Changes
*None. Existing `vaults` table and `Vault` model fully support batch-created vaults.*

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| Form Request | `app/Http/Requests/Vaults/RegisterVaultBatchRequest.php` | Validates `paths` array: required, min 1, max 50, each path distinct non-empty string up to 1024 chars. |
| Value Object | `app/Support/VaultBatchResult.php` | Holds added vaults, skipped items with reasons, total notes indexed count, and generates human-friendly summary text. |
| Controller Action | `app/Http/Controllers/ExistingVaultController.php` | Implements `batchStore()`: loops paths, registers vaults, indexes notes, sets first vault current, flashes toast, redirects. |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Component | `resources/js/components/DirectoryBrowserDialog.vue` | Adds `multiple` prop, checkbox UI per item, selection toggling, select-all bar, persistent `selectedPaths`, footer count & fallback. Emits `select` and `select-multiple`. |
| Component | `resources/js/components/vaults/AddExistingVaultDialog.vue` | Integrates with multi-select browser; supports single and batch modes with folder list, remove buttons, and batch submit to Wayfinder route. |

### Routes
| Method | URI | Name | Controller@action | Middleware |
|---|---|---|---|---|
| `POST` | `vaults/existing/batch` | `vaults.existing.batch` | `ExistingVaultController@batchStore` | `web` |

---

## 3. Implementation Tasks

- [ ] **T1 — Create `VaultBatchResult` Value Object**
  - Files: `app/Support/VaultBatchResult.php`
  - Details:
    - Immutable class with properties: `array $added`, `array $skipped`, `int $notesIndexed = 0`.
    - Methods: `addedCount(): int`, `skippedCount(): int`, `isFullSuccess(): bool`, `isPartialSuccess(): bool`, `isFailure(): bool`, `summary(): string`.
    - Generates user-friendly summary format:
      - e.g., `"3 vaults added. 14 note(s) indexed."`
      - e.g., `"2 vaults added. 8 note(s) indexed. 1 skipped: Notes (A vault named “Notes” already exists)."`
  - Covers: [FR-07, FR-08]

- [ ] **T2 — Create `RegisterVaultBatchRequest` Form Request**
  - Files: `app/Http/Requests/Vaults/RegisterVaultBatchRequest.php`
  - Command: `php artisan make:request Vaults/RegisterVaultBatchRequest --no-interaction`
  - Details:
    - `authorize()` returns `true`.
    - Rules:
      - `'paths'` => `['required', 'array', 'min:1', 'max:50']`
      - `'paths.*'` => `['required', 'string', 'max:1024', 'distinct']`
    - Custom messages for `paths.required`, `paths.min`, `paths.*.required`, and `paths.*.distinct`.
  - Covers: [FR-07]

- [ ] **T3 — Implement `ExistingVaultController::batchStore` and Route**
  - Files: `app/Http/Controllers/ExistingVaultController.php`, `routes/vaults.php`
  - Details:
    - Add route `Route::post('vaults/existing/batch', [ExistingVaultController::class, 'batchStore'])->name('vaults.existing.batch');` in `routes/vaults.php`.
    - In `ExistingVaultController::batchStore(RegisterVaultBatchRequest $request, VaultService $vaults, VaultIndexService $index)`:
      - Initialize `$added = []`, `$skipped = []`, `$totalNotesIndexed = 0`.
      - Loop `$request->validated('paths')`:
        - Get suggested name: `$name = $vaults->suggestedNameFor($path);`
        - Try `$vault = $vaults->register($path, $name);`
        - Try indexing: `$reconcile = $index->reconcile($vault, IndexMode::Quick); $totalNotesIndexed += $reconcile->added;`
        - Catch `NoteOperationException` without failing.
        - Add to `$added`.
        - Catch `VaultOperationException $e`: add `['path' => $path, 'reason' => $e->getMessage()]` to `$skipped`.
      - If `$added` is empty:
        - Throw `ValidationException::withMessages(['paths' => ...])` summarizing failure reasons.
      - If `$added` is not empty:
        - Open first vault: `$vaults->open($added[0]);`
        - Create `$result = new VaultBatchResult($added, $skipped, $totalNotesIndexed);`
        - Flash toast:
          `Inertia::flash('toast', ['type' => $result->skippedCount() > 0 ? 'warning' : 'success', 'message' => $result->summary()]);`
        - Redirect: if count is 1 and 0 skipped, redirect to `workspace`; otherwise redirect to `vaults.index`.
  - Covers: [FR-07, FR-08]

- [ ] **T4 — Enhance `DirectoryBrowserDialog.vue` for Multi-Select**
  - Files: `resources/js/components/DirectoryBrowserDialog.vue`
  - Details:
    - Add prop `multiple?: boolean` (default: `false`).
    - Add emit `'select-multiple': [paths: string[]]`.
    - Add state: `selectedPaths = ref<string[]>([])`.
    - When `multiple=true`:
      - Folder grid rows render `<Checkbox>` from `@/components/ui/checkbox`.
      - Clicking row card or checkbox toggles folder path in `selectedPaths`.
      - Clicking the `>` chevron button triggers `@click.stop="enterDirectory(dir)"` to navigate into subfolder.
      - Double-clicking row also triggers `enterDirectory(dir)`.
      - Add "Select all in folder" / "Clear" toolbar row above grid.
      - Footer preview:
        - If `selectedPaths.length === 0`: shows `Target path: {{ currentPath }}` and button `Select folder`.
        - If `selectedPaths.length === 1`: shows `Selected 1 folder: {{ selectedPaths[0] }}` and button `Select 1 folder`.
        - If `selectedPaths.length > 1`: shows `Selected: {{ selectedPaths.length }} folders` with badge and button `Select {{ selectedPaths.length }} folders`.
      - `confirmSelection()`:
        - When `multiple=true`: emits `select-multiple` with `selectedPaths.length > 0 ? selectedPaths.value : [currentPath.value]`. Also emits `select` if 1 path. Closes dialog.
        - When `multiple=false`: preserves existing single path emit and closes dialog.
      - Watcher on `open`: resets `selectedPaths.value = []` when opening fresh dialog.
  - Covers: [FR-01, FR-02, FR-03, FR-04, FR-05]

- [ ] **T5 — Update `AddExistingVaultDialog.vue` for Multi-Vault Support**
  - Files: `resources/js/components/vaults/AddExistingVaultDialog.vue`
  - Details:
    - Pass `:multiple="true"` to `<DirectoryBrowserDialog>`.
    - Handle `@select-multiple="onFoldersSelected"`.
    - Maintain `mode = ref<'single' | 'batch'>('single')`.
    - Add `batchForm = useForm({ paths: [] as string[] })`.
    - In `onFoldersSelected(paths: string[])`:
      - If `paths.length === 1`:
        - `mode.value = 'single'`.
        - Set `form.path = paths[0]`.
        - Set `form.name = suggestedNameFrom(paths[0])`.
      - If `paths.length > 1`:
        - `mode.value = 'batch'`.
        - `batchForm.paths = [...paths]`.
    - When in `batch` mode:
      - Display a card list of selected folders showing folder icon, basename, path, and a remove button (`X`).
      - Show summary: `{{ batchForm.paths.length }} folders selected`.
      - Provide "Choose different folders..." button to re-open browser.
      - Provide "Switch to single folder" button to revert.
      - Submit button: "Add {{ batchForm.paths.length }} vaults", calling `batchForm.post(batch.url(), ...)` (or `batchStore()` from Wayfinder routes).
      - On success: close modal, reset form, revert to single mode.
      - Render validation error for `batchForm.errors.paths` if returned.
  - Covers: [FR-06]

- [ ] **T6 — Feature Tests & Formatting Verification**
  - Files: `tests/Feature/Vaults/ExistingVaultBatchTest.php`
  - Command: `php artisan make:test --pest Vaults/ExistingVaultBatchTest --no-interaction`
  - Details:
    - Test batch registering multiple valid directories succeeds, registers all vaults, indexes notes, sets first vault as current, and redirects to `vaults.index`.
    - Test single path in batch redirects to `workspace`.
    - Test partial failure: 1 duplicate path + 1 valid path -> valid is registered, duplicate skipped, warning toast flashed, redirects to `vaults.index`.
    - Test complete failure: all invalid or duplicate paths -> raises `ValidationException` on `paths`.
    - Test overlapping directories in batch: parent and child in same request -> parent registered, child skipped with overlap warning.
    - Test validation rules: empty paths array rejected, duplicate items in array rejected.
    - Run Pint: `vendor/bin/pint --dirty --format agent`.
  - Covers: [FR-07, FR-08]

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/Feature/Vaults/ExistingVaultBatchTest.php` | Batch registering multiple folders successfully | FR-07 |
| `tests/Feature/Vaults/ExistingVaultBatchTest.php` | Single folder batch redirects to workspace | FR-07 |
| `tests/Feature/Vaults/ExistingVaultBatchTest.php` | Partial success with duplicate skipped & warning toast | FR-08 |
| `tests/Feature/Vaults/ExistingVaultBatchTest.php` | Complete failure returns validation error | FR-08 |
| `tests/Feature/Vaults/ExistingVaultBatchTest.php` | Overlapping folders in batch handled gracefully | FR-08 |
| `tests/Feature/Vaults/ExistingVaultBatchTest.php` | Payload validation (empty, duplicate paths, max items) | FR-07 |
| `tests/Feature/Vaults/ExistingVaultTest.php` | Existing single registration regression tests pass | FR-05 |
| `tests/Feature/DirectoryBrowserTest.php` | Existing directory browsing tests pass | FR-01 |

**Test scope for QA**:
- `tests/Feature/Vaults/ExistingVaultBatchTest.php`
- `tests/Feature/Vaults/ExistingVaultTest.php`
- `tests/Feature/DirectoryBrowserTest.php`
- `vendor/bin/phpstan analyse`
- `npm run types:check`

---

## 5. Risks & Mitigations
- **Risk**: User accidentally selects root drive or unsafe system folder in multi-select mode.  
  **Mitigation**: `VaultService::assertSafeFolder` automatically protects against filesystem roots and system folders; `ExistingVaultController::batchStore` catches these and skips them safely.
- **Risk**: Multi-select breaks existing single-select usages like `Storage.vue`.  
  **Mitigation**: The `multiple` prop defaults to `false`, preserving 100% of the existing single-selection emit and behavior for `Storage.vue`.

---

## 6. Open Questions
*None. All requirements, interactions, and edge cases are fully defined.*

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-11-07 | Initial plan | Complete architecture, UX design, tasks, and test plan |

