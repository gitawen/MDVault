# Implementation: Sidebar Vault Tree

## Metadata
- **Feature Name**: Sidebar Vault Tree
- **Feature ID**: ui-sidebar-vault-tree
- **Author**: Senior Developer
- **Plan Revision Implemented**: Revision 1
- **Status**: READY FOR QA

---

## 1. Task Progress
| Task | Status | Notes |
|---|---|---|
| T1 - `useWorkspaceTree` composable | DONE | Read-only computed values over `usePage()`. |
| T2 - Adapt `NoteTree` / `NoteTreeItem` | DONE | Header removed, `defineExpose({ newNote, newFolder })`, `note-selected` emit, sidebar tokens, `aria-current`, touch-visible menu buttons. |
| T3 - `SidebarVaultItem` + `NavVaults` | DONE | `NoteTree` stays mounted under `v-show`; `right-13` is valid in Tailwind v4 (dynamic spacing), so no arbitrary value was needed. |
| T4 - Remove Notes panel from `Workspace.vue` | DONE | `tree`, `folders`, `canTrash` props kept with comment. |
| T5 - Backend regression tests | DONE | Two new tests in `WorkspaceTest.php` (second uses a dataset). |
| T6 - Types, build, format | DONE | See section 3. Manual checks marked "for user". |

---

## 2. Files Changed
| Action | Path | Summary |
|---|---|---|
| created | `resources/js/composables/useWorkspaceTree.ts` | Typed read-only selectors: `onWorkspace`, `currentVaultUuid`, `tree`, `folders`, `canTrash`, `selectedUuid`, `available`. |
| created | `resources/js/components/vaults/SidebarVaultItem.vue` | Vault row: icon, name, chevron, three row actions, hosted `NoteTree`. |
| modified | `resources/js/components/NavVaults.vue` | Renders `SidebarVaultItem`s; owns `currentExpanded` and the activate logic. |
| modified | `resources/js/components/notes/NoteTree.vue` | Header and reindex logic removed; exposes `newNote`/`newFolder`; emits `note-selected`. |
| modified | `resources/js/components/notes/NoteTreeItem.vue` | Sidebar colour tokens, `aria-current`, `noteSelected` on click, menu button visibility, indentation. |
| modified | `resources/js/components/notes/noteTreeActions.ts` | Added `noteSelected(): void`. |
| modified | `resources/js/pages/Workspace.vue` | Removed Notes aside, toggle and `treeOpen`; full-width editor; new empty copy. |
| modified | `tests/Feature/WorkspaceTest.php` | Added sidebar tree data contract tests. |

Touched files were formatted with the project formatter (`npx vp fmt`). The pre-existing unrelated changes were not touched. `npm run build` deletes the tracked `public/fonts-manifest.dev.json` as a side effect; it was restored with `git checkout`.

---

## 3. Verification Performed
| Command | Result |
|---|---|
| `vendor/bin/pint --dirty --format agent` | passed |
| `php artisan test --compact tests/Feature/WorkspaceTest.php tests/Feature/Vaults tests/Feature/Notes` | 107 passed (536 assertions) |
| `npm run types:check` | pass |
| `npm run test:js` | 12 files, 142 tests passed |
| `npm run build` | built successfully |

The full `php artisan test --compact` suite was not run (left to QA per the plan's test scope).

---

## 4. Deviations from Plan
- None of substance. `currentVault` in the composable is an extra internal computed (used by `currentVaultUuid` and `available`), as the plan describes in prose.

---

## 5. Notes for QA
- Manual checks M1-M8: **for user** (the app was not run in a browser or desktop shell).
  - M1 expand/collapse and actions while collapsed
  - M2 switching vaults
  - M3 row and item menu actions
  - M4 selected note highlight and full-width editor
  - M5 icon-collapsed sidebar
  - M6 mobile sheet closes on note tap
  - M7 Settings page collapsed vault, click goes to Workspace expanded
  - M8 external file appears; missing vault has no chevron
- Probe the row action offsets (`right-13`, `right-7`, `right-1`) against the `pr-20` button, and the `v-show` hosting of `NoteTree` so dialogs work while collapsed.
- `currentExpanded` resets to `true` on a current-vault change; clicking a non-current vault also sets it to `true` before the POST.

---

## 6. Fix Rounds
*None.*

## Fix Round 1 (QA-01, QA-02, QA-03)
File: `resources/js/components/vaults/SidebarVaultItem.vue`
- QA-01: replaced `pr-20` with `group-has-data-[sidebar=menu-action]/menu-item:pr-20` so tailwind-merge dedupes against the button's `...:pr-8`. Built CSS contains the `:pr-20` rule (`padding-right: calc(var(--spacing) * 20)`) with identical selector/specificity, emitted after `:pr-8`, so it wins even if both classes were present.
- QA-02: added `truncate` to the vault name span.
- QA-03: `aria-expanded` now bound to `hasTree ? expanded : undefined`.
- Verification: `npm run types:check` clean; `npm run build` succeeded; `public/fonts-manifest.dev.json` restored via git checkout.
