# Requirements: Multi-Select Existing Vaults

## Metadata
- **Feature Name**: Multi-Select Existing Vaults
- **Feature ID**: feat-multi-select-existing-vaults
- **Author**: System Analyst
- **Created Date**: 2026-11-07
- **Task Complexity**: Level 3 — Complex Development
- **Status**: APPROVED

---

## 1. Problem Statement
When setting up or migrating Markdown vaults in MDVault, users often have multiple vault folders stored in a common directory (e.g., `Documents/Notes/Work`, `Documents/Notes/Personal`, `Documents/Notes/Archive`). Currently, adding an existing vault requires opening the "Choose vault folder" browser modal, picking a single folder, submitting the form, and repeating this entire multi-step process for every single vault. Users need the ability to select and register multiple existing vault folders at once from the folder browser modal in a single operation.

---

## 2. Goals & Non-Goals

### In Scope (Goals)
- Add multi-selection capabilities to the `DirectoryBrowserDialog` component (`multiple` prop):
  - Checkboxes for each folder item in the directory grid.
  - Clicking a folder row or its checkbox toggles its selection state.
  - Clicking the `>` chevron button (or double-clicking) retains its role to navigate into subfolders.
  - "Select all in folder" toggle button for rapid selection of visible subfolders.
  - Selection persists as the user navigates across different directories.
  - Footer preview shows selected folder count and folder name badges.
  - 0-selection fallback: When 0 checkboxes are checked, clicking "Select folder" falls back to selecting the currently browsed directory (preserving single folder selection behavior).
- Update `AddExistingVaultDialog` to support both single and batch folder selection:
  - When 1 folder is selected: displays the familiar single-vault form with editable Name and Description fields.
  - When multiple folders are selected: displays a batch review view showing selected folders with their derived vault names, paths, and an option to remove any folder before submitting.
- Introduce a dedicated backend batch endpoint `POST /vaults/existing/batch` with `RegisterVaultBatchRequest` and `VaultBatchResult`:
  - Registers each folder using its basename as suggested name.
  - Indexes notes for each newly registered vault.
  - Uses best-effort processing: registers valid vaults and gracefully collects skipped folders (e.g. duplicate names, overlapping paths, or unsafe roots).
  - Opens the first registered vault as the active vault.
  - Redirects to `vaults.index` with a detailed flash toast summarizing added vaults and skipped items (or redirects to `workspace` if only 1 vault was registered).

### Out of Scope (Non-Goals)
- Bulk file creation or moving folders on disk.
- Custom description editing per vault during a batch addition (descriptions can be added after import via the existing Rename/Edit vault dialog).
- Encrypted vault password setup during batch addition (if an encrypted vault header is detected during registration, it is registered in locked status via `EncryptionHeaderService::adopt()`, identical to existing single registration).

---

## 3. User Personas & Stories
- **As a** knowledge worker or researcher migrating note folders to MDVault,  
  **I want to** check multiple folders in the "Choose vault folder" modal and add them together,  
  **So that** I don't have to repeat the import dialog workflow dozens of times.

- **As a** user who navigated into a specific vault directory,  
  **I want to** click "Select folder" without checking any boxes to select the folder I am currently inside,  
  **So that** the browser remains fast and frictionless when importing a single directory.

---

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | Multi-Select Prop & Checkbox UI | `DirectoryBrowserDialog` accepts a `multiple?: boolean` prop (default `false`). When `true`, each folder item in the grid renders a visible checkbox on the left. | Given `multiple=true`, When folders are listed, Then each folder card renders a checkbox and clicking the card or checkbox toggles its selection. |
| **FR-02** | Independent Subfolder Navigation | In multi-select mode, clicking the `>` chevron button on a folder card or double-clicking navigates into that folder without toggling selection. | Given a folder card with chevron `>`, When user clicks the chevron, Then the browser navigates into that subfolder and does not toggle its checkbox. |
| **FR-03** | Persistent Cross-Directory Selection | Folders checked by the user remain selected in `selectedPaths` even if the user navigates to parent or child directories. | Given folder `A` is checked in directory `/notes`, When user navigates into `/notes/archive` and checks folder `B`, Then both `/notes/A` and `/notes/archive/B` remain selected. |
| **FR-04** | "Select All" & "Clear" in View | In multi-select mode, a toolbar row provides a "Select all in folder" toggle for visible filtered items and a "Clear" action when items are selected. | Given visible subfolders in current view, When user clicks "Select all in folder", Then all visible subfolders are checked; clicking "Clear" unchecks all selections. |
| **FR-05** | Fallback When 0 Checkboxes Checked | When `multiple=true` and `selectedPaths` is empty, the footer displays the current path as target and the action button says "Select folder". | Given no checkboxes are checked, When user clicks "Select folder", Then the currently browsed directory path is emitted as a single-item array `[currentPath]`. |
| **FR-06** | Batch Review in `AddExistingVaultDialog` | When multiple paths are selected from the browser, `AddExistingVaultDialog` enters batch mode showing the list of selected folders with remove buttons, derived names, and an "Add X vaults" submit action. | Given 3 folders selected in picker, When modal confirms, Then `AddExistingVaultDialog` displays the 3 folders with remove controls and an "Add 3 vaults" button. |
| **FR-07** | Backend Batch Registration Endpoint | `POST /vaults/existing/batch` accepts an array of `paths`. It registers each folder, reconciles/indexes notes, activates the first registered vault, and handles duplicates/overlaps gracefully. | Given valid paths `[pathA, pathB]`, When posted to `/vaults/existing/batch`, Then both vaults are created in SQLite, indexed, first opened, and user receives a success flash toast. |
| **FR-08** | Duplicate & Overlap Error Handling | If a selected path is already registered, overlaps an existing vault, or shares a duplicate name with another vault, it is skipped with a clear reason in the response toast without rolling back valid sibling vaults. | Given `pathA` (new) and `pathB` (already registered), When submitted, Then `pathA` is registered, `pathB` is skipped, and user sees a warning toast noting `pathB` was skipped. |

---

## 5. Non-Functional Requirements
- **Security & Authorization**: Validate that all submitted paths are absolute, directories, writable, and not unsafe (not root of drive, not the application storage root itself, not the user documents folder itself).
- **Performance**: Note indexing during batch registration uses `IndexMode::Quick` per vault; batch requests with up to 50 folders complete smoothly.
- **Accessibility & UX**:
  - Checkboxes use Reka UI / Tailwind accessible primitives (`CheckboxRoot`, ARIA checked state).
  - Clear visual feedback with selected count badges in footer.
  - Single-root Vue components adhering to Inertia v3 / Vue 3 rules.
- **Reliability & Data Integrity**:
  - Each vault is registered in its own database transaction via `VaultService::register()`.
  - SQLite database integrity is preserved: failure of one folder does not corrupt or roll back previously registered vaults.

---

## 6. Technical Constraints & Context
- Framework: Laravel 13 (PHP 8.4)
- Frontend: Inertia.js v3 + Vue 3 + Tailwind CSS v4
- UI Primitives: Reka UI (`@/components/ui/checkbox`) & Lucide Vue icons
- Routing: Laravel Wayfinder (`@/actions/`, `@/routes/`)
- Testing: Pest 5
- Code Style: Laravel Pint (`vendor/bin/pint --dirty --format agent`)
- Database: SQLite

---

## 7. Risks & Assumptions
- **Assumption 1**: Vault names can be safely derived from folder basenames via `VaultService::suggestedNameFor()`. If a user wants to rename a vault later, they can use the existing Rename vault feature.
- **Risk 1 & Mitigation**: Selected paths within the same batch could overlap (e.g. user selects both `/parent` and `/parent/child`).  
  *Mitigation*: Processing order registers the parent first; when the child is processed, `VaultService::assertNoOverlap()` detects the parent and adds the child to `$skipped` with an explanatory message.

---

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified
- [x] Approved to proceed to Planning (`plan.md`)

