# ADR: Batch Vault Registration Semantics & Multi-Select Picker UX

- **Status**: Approved
- **Date**: 2026-11-07
- **Context**: Enabling multi-selection in the "Choose vault folder" modal to add multiple existing Markdown vault folders at once.

## Context
MDVault allows users to import existing folders containing Markdown files into the application. Previously, the flow only allowed selecting and importing one folder at a time through a single-path dialog. Users with multiple folders in their notes directory experienced high friction having to repeatedly reopen and fill the modal.

Two key architectural decisions were required:
1. **Picker UX**: How to balance browsing into subfolders vs selecting multiple subfolders in the same interface, and handling 0-selection fallbacks.
2. **Backend API & Failure Semantics**: Whether to extend the single-vault endpoint or create a batch endpoint, and how to handle validation, duplicates, and partial failures.

## Options Considered

### 1. Picker Interaction Design
- **Option A (Click row to enter, explicit button to select)**: Clicking a folder navigates into it; selecting requires clicking a small "Select" button on each card.  
  *Downside*: Selecting 5 folders requires aiming for small button targets; doesn't feel like a standard multi-select list.
- **Option B (Checkboxes + Row click to toggle, Chevron to enter - Chosen)**: Each folder card has a visible checkbox. Clicking the card or checkbox toggles its selection. An explicit `>` chevron button on the card (and double-clicking) enters the subfolder.  
  *Benefit*: Very fast multi-selection while preserving intuitive deep navigation. Visual checkbox immediately informs the user that multiple items can be checked.

### 2. Backend Endpoint Design
- **Option A (Overload `POST /vaults/existing`)**: Allow `path` to be either a string or array of strings.  
  *Downside*: Muddies validation rules in `RegisterVaultRequest`, forces conditional logic for custom names and descriptions, complicates typed Wayfinder routes.
- **Option B (Dedicated `POST /vaults/existing/batch` - Chosen)**: Keep `POST /vaults/existing` dedicated to single-vault imports with explicit custom names, and introduce `POST /vaults/existing/batch` with `RegisterVaultBatchRequest` accepting `paths: string[]`.  
  *Benefit*: Clean separation of concerns, strong type safety, isolated test coverage, and clean Form Request validation.

### 3. Failure & Duplication Semantics
- **Option A (All-or-Nothing Atomic Rollback)**: If any folder in the batch is duplicate or overlapping, abort the entire batch and roll back.  
  *Downside*: Poor user experience. If a user selects 10 folders and 1 was already added earlier, rejecting the 9 valid folders frustrates the user.
- **Option B (Best-Effort Registration with Rich Reporting - Chosen)**: Register each valid folder in its own transaction, catch individual `VaultOperationException`s to populate a `$skipped` list, index notes for all added vaults, and return a comprehensive toast summary. If zero vaults could be added, fail with a `ValidationException`.  
  *Benefit*: Maximizes user progress, reports exact reasons for any skipped folders, and maintains SQLite database integrity.

## Decision
1. `DirectoryBrowserDialog.vue` gains a `multiple?: boolean` prop (default `false`). When `true`, it presents checkboxes, persists `selectedPaths` across navigation, provides a "Select all in folder" helper, and falls back to `[currentPath]` if 0 checkboxes are checked.
2. `AddExistingVaultDialog.vue` supports both `single` and `batch` modes, rendering a preview of selected folders before submission.
3. `POST /vaults/existing/batch` processes batches via `ExistingVaultController::batchStore()`, using `VaultBatchResult` to summarize added and skipped vaults.
4. When multiple vaults are added, the first vault is activated as current and the user is redirected to `vaults.index` with an informative toast so they can see all newly imported vaults.

