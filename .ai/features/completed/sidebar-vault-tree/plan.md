# Plan: Sidebar Vault Tree (Navigation Consolidation)

## Metadata
- **Feature Name**: Sidebar Vault Tree
- **Feature ID**: ui-sidebar-vault-tree
- **Author**: System Analyst
- **Created Date**: 2026-10-03
- **Task Complexity**: Level 3, Complex Development
- **Master Plan Phase**: Phase 3, Markdown Filesystem (§52), UI refinement to match §36, Main Application Layout. It reuses the Phase 5 (§54) external-change wiring without changing it.
- **Branch**: `feature/sidebar-vault-tree`
- **Requirements**: `requirements.md`
- **Status**: APPROVED

---

## 1. Summary
The note tree moves from the Workspace "Notes" panel into the app sidebar. It nests under the current vault, which becomes a collapsible row with new-note, new-folder and refresh actions. Tree data stays a Workspace page prop. The sidebar reads it reactively through `usePage()` and shows it only when the page is Workspace. This means no backend, middleware or endpoint changes, and the existing tree-signature polling keeps working unchanged. `Workspace.vue` loses the panel and mobile toggle, and the editor gets the full width.

---

## 2. Architecture & Design
- **Approach**: page-scoped tree with a sidebar consumer.
  - `WorkspaceController` keeps providing `tree`, `folders`, `canTrash`, `treeSignature` and `note`.
  - A small read-only composable, `useWorkspaceTree()`, turns `usePage()` into typed computed values. Tree data counts as available only when `page.component === 'Workspace'`, `currentVault.status === 'active'` and `tree !== null`.
  - `NavVaults` renders one `SidebarVaultItem` per vault. The current item hosts `NoteTree`, which keeps owning the create, rename, move and delete dialogs.
  - `NoteTree` drops its header and exposes `newNote(folder)` / `newFolder(parent)` so the row actions can open its dialogs.
  - `useExternalChanges` stays in `Workspace.vue`, which owns the editor. Its `router.reload({ only: ['tree','folders','treeSignature'] })` updates `page.props`, and the sidebar picks the change up reactively.
- **Alternatives considered**:
  - Shared closure prop in `HandleInertiaRequests`. Rejected: `browse()` does a full recursive directory scan plus a notes query on every request (Settings saves, vault actions, redirects), and it would still need polling off the Workspace page to stay fresh.
  - `Inertia::defer` shared prop. Rejected: an extra request on every page and a loading flash.
  - `Inertia::optional` shared prop, loaded through a client `router.reload({ only: ['tree'] })` on non-Workspace pages. Rejected for now: scan cost and complexity for little value. It is a possible follow-up if the user wants the tree on Settings.
  - `Inertia::once`. Rejected: the tree would be stale after mutations, with no clean way to invalidate it.
  - A new JSON endpoint. Excluded by the request.
- **Decision Records**: `.ai/decisions/sidebar-note-tree-data-source.md`

### Data Model Changes
| Table | Change | Columns / Indexes / Constraints |
|---|---|---|
| none | none | none |

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| Controller | `app/Http/Controllers/WorkspaceController.php` | Unchanged. It still supplies `tree`, `folders`, `treeSignature`, `canTrash` and `note`. |
| Middleware | `app/Http/Middleware/HandleInertiaRequests.php` | Unchanged. No tree in shared props (FR-13). |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Composable (new) | `resources/js/composables/useWorkspaceTree.ts` | Read-only, typed computed values over `usePage()`: `onWorkspace`, `currentVaultUuid`, `tree`, `folders`, `canTrash`, `selectedUuid`, `available`. No logic beyond selecting props. |
| Component (new) | `resources/js/components/vaults/SidebarVaultItem.vue` | One `SidebarMenuItem` per vault: button (icon, name, chevron), row actions (current vault with data only), and the hosted `NoteTree` (`v-show` when expanded). |
| Component (modify) | `resources/js/components/NavVaults.vue` | Renders `SidebarVaultItem`s and owns the single `currentExpanded` ref. Handles open, toggle and navigate clicks. |
| Component (modify) | `resources/js/components/notes/NoteTree.vue` | Header removed; `defineExpose({ newNote, newFolder })`; emits `note-selected`; list wrapper restyled for the sidebar. |
| Component (modify) | `resources/js/components/notes/NoteTreeItem.vue` | Sidebar colour tokens, `aria-current` on the selected note, menu buttons visible on touch, calls `actions.noteSelected()` on link click. |
| Type (modify) | `resources/js/components/notes/noteTreeActions.ts` | Adds `noteSelected(): void` to `NoteTreeActions`. |
| Page (modify) | `resources/js/pages/Workspace.vue` | Removes the Notes `aside`, `PanelLeft` toggle, `treeOpen`, `NoteTree` import; full-width editor; updated empty copy. |

### Routes
| Method | URI | Name | Controller@action | Middleware |
|---|---|---|---|---|
| none new | none | none | Reuses `vaults.open` (POST), `vaults.reindex` (POST), `workspace` (GET), `notes.show` (GET) | none |

---

## 3. Implementation Tasks
Tasks are ordered so that each one leaves the app working. Tasks T1–T3 are additive; the panel is removed only in T4.

- [ ] **T1: `useWorkspaceTree` composable**
  - Files: `resources/js/composables/useWorkspaceTree.ts` (new)
  - Details:
    - Use `usePage()`. Return the following computed values:
      - `onWorkspace = page.component === 'Workspace'`
      - `currentVaultUuid = (page.props.currentVault as VaultSummary | null | undefined)?.uuid ?? null`. Only meaningful on Workspace; elsewhere fall back to `page.props.vaults.find(v => v.is_current)?.uuid ?? null`.
      - `tree` (`NoteTreeNode[] | null`)
      - `folders` (`string[]`, default `['']`)
      - `canTrash` (`boolean`, default `false`)
      - `selectedUuid = (page.props.note as NoteDetail | null | undefined)?.uuid ?? null`
      - `available = onWorkspace && currentVault?.status === 'active' && Array.isArray(tree)`
    - Pure selection and casting only: no fetching, no path or filesystem logic.
  - Covers: FR-02, FR-11, FR-13

- [ ] **T2: Adapt `NoteTree` / `NoteTreeItem` for the sidebar (panel still in use)**
  - Files: `resources/js/components/notes/noteTreeActions.ts`, `resources/js/components/notes/NoteTree.vue`, `resources/js/components/notes/NoteTreeItem.vue`
  - Details:
    - `noteTreeActions.ts`: add `noteSelected(): void` to `NoteTreeActions`.
    - `NoteTree.vue`:
      - Remove the header block (the "Notes" label and the three buttons), the `reindexing` ref, `runReindex`, the `reindex` import and the `FilePlus`, `FolderPlus`, `RefreshCw` and `Button` imports.
      - Add `defineEmits<{ 'note-selected': [] }>()`. In `provide(...)`, implement `noteSelected() { emit('note-selected') }`.
      - Add `defineExpose({ newNote: (folder = '') => {...}, newFolder: (parent = '') => {...} })`, reusing the bodies of the existing provided `newNote`/`newFolder`.
      - Root becomes `<div class="flex flex-col">` with the list `<div class="py-1">`. Keep the empty-state paragraph (`text-xs`, `px-2`). Keep all six dialogs.
    - `NoteTreeItem.vue`:
      - Swap `hover:bg-accent` / `bg-accent` for `hover:bg-sidebar-accent hover:text-sidebar-accent-foreground` / `bg-sidebar-accent text-sidebar-accent-foreground font-medium` on the selected note.
      - Add `:aria-current="node.uuid === selectedUuid ? 'page' : undefined"` on the note `Link`.
      - Add `@click="actions?.noteSelected()"` on the note `Link`, keeping `:only="['note']" preserve-state preserve-scroll`.
      - Menu trigger buttons: replace `opacity-0 group-hover:opacity-100` with `md:opacity-0 md:group-hover:opacity-100 md:group-focus-within:opacity-100 data-[state=open]:opacity-100`.
      - Indentation: base `padding-left` of `0.5rem + depth * 0.75rem` so it fits the 16rem sidebar.
    - In this interim state, `Workspace.vue`'s panel loses its header buttons. This is acceptable because T3 adds them to the sidebar in the same PR; T2 and T3 may be committed together.
  - Covers: FR-06, FR-08, FR-11

- [ ] **T3: `SidebarVaultItem` + `NavVaults` rewrite**
  - Files: `resources/js/components/vaults/SidebarVaultItem.vue` (new), `resources/js/components/NavVaults.vue`
  - Details:
    - **`SidebarVaultItem.vue`**
      - Props:
        - `vault: VaultSummary`
        - `expanded: boolean` (already resolved by the parent: current, active, data available, and the user's toggle)
        - `canExpand: boolean` (non-missing)
        - `hasTree: boolean` (`isCurrent && available`)
        - `tree: NoteTreeNode[]`, `folders: string[]`, `canTrash: boolean`, `selectedUuid: string | null`
      - Emits `activate`.
      - Root: `<SidebarMenuItem>`.
      - `SidebarMenuButton`:
        - `:is-active="vault.is_current"`, `:tooltip="vault.name"`, `as="button"`
        - `:aria-expanded="canExpand ? expanded : undefined"`
        - `:class="{ 'pr-20': hasTree }"`, `@click="emit('activate')"`
        - Content, in order:
          1. Icon: `TriangleAlert` if missing, else `FolderOpen` when `expanded`, else `FolderClosed`. The icon must be the first child so icon mode shows it.
          2. `<span>{{ vault.name }}</span>` plus the existing sr-only "(folder missing)".
          3. When `canExpand`: `<ChevronRight class="ml-auto transition-transform group-data-[collapsible=icon]:hidden" :class="{ 'rotate-90': expanded }" />`.
      - When `hasTree`, render three `SidebarMenuAction show-on-hover`, each with `title` and `aria-label`, using explicit offsets `class="right-13"`, `right-7`, `right-1`:
        - New note: `treeRef?.newNote('')`
        - New folder: `treeRef?.newFolder('')`
        - Re-index: local `reindexing` ref; `router.post(reindex.url(vault.uuid), {}, { preserveScroll: true, onFinish: () => (reindexing.value = false) })`, `:disabled="reindexing"`, `RefreshCw` with `animate-spin` while in flight. This logic is moved verbatim from `NoteTree`.
        - Verify `right-13` exists in Tailwind v4's spacing scale. If not, use `right-[3.25rem]`.
      - When `hasTree`, render `<div v-show="expanded" class="group-data-[collapsible=icon]:hidden">` containing `<NoteTree ref="treeRef" ...>`. `NoteTree` stays mounted while collapsed so the dialogs (and therefore the row actions) work. Forward `@note-selected` to: `if (isMobile) setOpenMobile(false)` using `useSidebar()`.
      - Do not use `Collapsible`/`CollapsibleContent` at the vault level (it unmounts content). Single root element.
    - **`NavVaults.vue`**
      - Keep the group label, the "Manage vaults" `SidebarGroupAction` and the empty "Create a vault" link.
      - Use `useWorkspaceTree()` and `useSidebar()` (`state`, `isMobile`).
      - `const currentExpanded = ref(true)`. Add a `watch` on the current vault uuid (from `page.props.vaults.find(v => v.is_current)?.uuid`) that resets `currentExpanded` to `true` when it changes.
      - For each vault compute:
        - `isCurrent = vault.is_current`
        - `canExpand = vault.status !== 'missing'`
        - `hasTree = isCurrent && available`
        - `expanded = hasTree && currentExpanded`
      - `onActivate(vault)`:
        1. If `vault.status === 'missing' || !vault.is_current`, call `router.post(open.url(vault.uuid))` (today's behaviour). For a non-missing vault, also set `currentExpanded = true` so it opens expanded after the redirect.
        2. Else, if not `onWorkspace`, set `currentExpanded = true` and `router.visit(workspace())`.
        3. Else, if the sidebar is in desktop icon mode (`state === 'collapsed' && !isMobile`), do nothing.
        4. Else, toggle `currentExpanded`.
  - Covers: FR-01, FR-02, FR-03, FR-04, FR-05, FR-08, FR-09, FR-10, FR-11

- [ ] **T4: Remove the Notes panel from `Workspace.vue`**
  - Files: `resources/js/pages/Workspace.vue`
  - Details:
    - Remove the `PanelLeft` import, the `NoteTree` import, `treeOpen` and its comment, the toggle `Button` in the header bar, and the `<aside>` block.
    - The content wrapper `v-if` becomes `currentVault && currentVault.status === 'active'` (drop `tree !== null`) with class `flex min-h-0 flex-1 flex-col`. `<main>` stays `flex min-h-0 min-w-0 flex-1 flex-col overflow-auto p-4`.
    - Empty copy: "Select a note from the sidebar, or create a new one."
    - Keep `tree`, `folders` and `canTrash` in `defineProps`, with a comment saying they are consumed by the sidebar through `usePage()` and declared so they do not fall through as attributes on the root element.
    - Keep `useExternalChanges`, `OrphanSaveNotice`, the header bar (name, path, badge, Close vault), the missing alert and `StatusBar` unchanged.
  - Covers: FR-07, FR-12

- [ ] **T5: Backend regression tests for the data contract**
  - Files: `tests/Feature/WorkspaceTest.php`
  - Details: add two tests in the existing style (temp dir + `fakeDocumentsDirectory` + `try/finally`):
    1. **"the workspace provides the sidebar tree data for an active vault with an open note"**
       - Setup: create and open a vault, write `Projects/a.md`, reconcile `IndexMode::Full`.
       - GET `notes.show` for that note.
       - Assert `component('Workspace')`, `currentVault.uuid`, `has('tree')`, `has('folders')`, `has('canTrash')`, `note.uuid` equals the note's uuid, and that the `Projects` folder node has `open === true`.
    2. **"non-workspace pages do not build the note tree"**
       - Same vault setup.
       - For `settings.general.edit` and `vaults.index` (use a Pest dataset), assert `->missing('tree')->missing('folders')->missing('treeSignature')` and `->where('vaults.0.is_current', true)`.
    - Run `vendor/bin/pint --dirty --format agent`.
  - Covers: FR-02, FR-11, FR-13

- [ ] **T6: Type check, build, format**
  - Files: none new
  - Details: run `npm run types:check`, `npm run build` and `npm run test:js` (`useExternalChanges`/`changeChecker` tests must stay green), and fix any issues. Record in `implementation.md` the outcome of manual checks M1–M8 below, if the developer can run the app; otherwise mark them "for user".
  - Covers: all FRs (build integrity)

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/Feature/WorkspaceTest.php` (new test 1) | Workspace and `notes.show` supply `tree`, `folders`, `canTrash` and `note.uuid`; the ancestor folder is `open` | FR-02, FR-11 |
| `tests/Feature/WorkspaceTest.php` (new test 2, dataset) | Settings and Vaults pages do not include `tree`/`folders`/`treeSignature`; `vaults` is shared with `is_current` | FR-13 |
| `tests/Feature/WorkspaceTest.php` (existing) | `tree` is null for a missing vault; `treeSignature` matches the check; partial `tree,folders,treeSignature` reload works | FR-10, FR-12 |
| `tests/Feature/Vaults/VaultManagementTest.php` (existing) | `vaults.open` still redirects to Workspace | FR-03 |
| `tests/Feature/Notes/NoteManagementTest.php` (existing) | Create, rename, move, delete and re-index endpoints used by the dialogs and actions are unchanged | FR-05, FR-06 |
| JS tests (`npm run test:js`, existing) | External-change checker behaviour is unchanged | FR-12 |

No existing PHP test asserts on Notes panel markup, so no existing test needs editing. If QA finds one, update it to the new contract rather than deleting it.

**Manual verification (QA records, or hands to the user):**
- **M1** Desktop Workspace: the active vault is expanded with its tree; clicking its row collapses and expands it; the actions work while collapsed.
- **M2** Clicking another vault opens it and shows it expanded; the previous vault is no longer expanded.
- **M3** New note, New folder and Re-index from the row actions; folder and note menus (new here, delete folder, rename, move, delete) all work from the sidebar.
- **M4** The selected note is highlighted; switching notes moves the highlight; the editor fills the full width; there is no Notes panel and no toggle.
- **M5** Icon-collapsed sidebar: no tree, chevrons or actions; vault tooltips show.
- **M6** Mobile width: open the sheet, see the tree, tap a note, the sheet closes and the note opens; menu buttons are visible without hover.
- **M7** Settings page: the active vault is collapsed with no actions; clicking it goes to Workspace expanded.
- **M8** Add a `.md` file externally: it appears in the sidebar within the polling interval. A missing vault shows TriangleAlert, no chevron and cannot expand.

**Test scope for QA**:
- `php artisan test --compact tests/Feature/WorkspaceTest.php tests/Feature/Vaults tests/Feature/Notes`, then the full `php artisan test --compact`
- `npm run types:check`
- `npm run test:js`
- `npm run build`
- `vendor/bin/pint --dirty --format agent --test`

---

## 5. Risks & Mitigations
- **Risk**: undeclared page props fall through as DOM attributes on `Workspace.vue`'s single root. **Mitigation**: keep `tree`, `folders` and `canTrash` declared (T4).
- **Risk**: the action icons overlap the name or chevron. **Mitigation**: explicit `right-*` offsets, `pr-20` on the button, `showOnHover` on desktop.
- **Risk**: row actions break while the vault is collapsed, because the dialogs live in `NoteTree`. **Mitigation**: `v-show`, not an unmounting Collapsible (T3).
- **Risk**: the mobile sheet closes while a dialog is open and unmounts it. **Mitigation**: close the sheet only on note link selection (`noteSelected`).
- **Risk**: on the Settings page the user expects to see the tree. **Mitigation**: clicking the active vault goes to Workspace expanded. The optional-prop follow-up is documented in the ADR.
- **Risk**: polling or selection breaks if a component other than Workspace starts reading these props. **Mitigation**: the `available` guard requires `page.component === 'Workspace'`.

---

## 6. Open Questions
None blocking. Defaults chosen and recorded for the orchestrator's briefing:
- [x] Q1: On non-Workspace pages, clicking the active vault goes to Workspace (default) rather than doing nothing.
- [x] Q2: Row actions use `showOnHover` on desktop (visible on hover and focus, always visible on mobile) rather than always visible.

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-10-03 | Initial plan | none |
