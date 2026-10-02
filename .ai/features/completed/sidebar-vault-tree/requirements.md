# Requirements: Sidebar Vault Tree (Navigation Consolidation)

## Metadata
- **Feature Name**: Sidebar Vault Tree
- **Feature ID**: ui-sidebar-vault-tree
- **Author**: System Analyst
- **Created Date**: 2026-10-03
- **Task Complexity**: Level 3, Complex Development
- **Master Plan Phase**: UI refinement of Phase 3, Markdown Filesystem (§52), to match §36, Main Application Layout. It reuses the Phase 5 external-change wiring (§54) without changing it.
- **Status**: APPROVED (the user approved "option 1")

---

## 1. Problem Statement
Navigation is currently split across two places:
- The app sidebar (`AppSidebar.vue` / `NavVaults.vue`) lists the vaults.
- The Workspace page (`Workspace.vue`) has its own "Notes" panel (`NoteTree.vue` / `NoteTreeItem.vue`) with header actions (new note, new folder, re-index) and a mobile-only toggle (`treeOpen`).

This takes width away from the editor, gives mobile two competing panels, and differs from the Master Plan §36 layout. In §36 the left column is the vault list, with the current vault's folders and notes nested under it.

## 2. Goals & Non-Goals
### In Scope (Goals)
- Every vault in the sidebar becomes a collapsible row with a chevron. Only the current, active (not missing) vault can expand and show its note tree.
- The active vault row has three small actions: new note, new folder, refresh (re-index). They reuse the existing dialogs and `vaults.reindex` behaviour.
- Folder and note rows keep their existing menu actions: new note here, new folder here, delete folder; rename, move, delete note.
- The Notes panel and its mobile toggle are removed from `Workspace.vue`. The editor gets the full content width.
- Mobile uses the existing sidebar sheet (`SidebarTrigger`).
- The tree is hidden when the sidebar is collapsed to icon mode.
- Missing vaults still show `TriangleAlert` and cannot expand.
- The selected note is still highlighted in the sidebar tree.
- External-change detection (tree signature polling and partial reloads) keeps working unchanged.

### Out of Scope (Non-Goals)
- Showing note trees for vaults that are not current, or for the current vault on pages other than Workspace.
- New endpoints, shared props or middleware changes to deliver tree data.
- New hover icons on folder rows; drag and drop; a search box in the sidebar.
- Remembering which folders are expanded across full page loads, beyond today's `node.open` behaviour.
- Any change to backend services, the database schema or how the tree is built.

## 3. User Personas & Stories
- **As a** writer using MDVault, **I want to** browse and open my current vault's notes from the sidebar, **so that** the editor has the full width and navigation lives in one place.
- **As a** writer with several vaults, **I want to** click another vault in the sidebar to open it and see its notes expanded, **so that** switching vaults is a single action.
- **As a** mobile or narrow-window user, **I want to** open the sidebar sheet to pick a note and have it close when I do, **so that** I can get back to writing.

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | Collapsible vault rows | Each vault row in the sidebar's "Vaults" group shows the vault icon, its name and a chevron at the end. The chevron is not shown for missing vaults. | Given registered vaults, when the sidebar renders, then every non-missing vault shows a chevron. The chevron is rotated (expanded) only for the current vault while it is expanded. |
| **FR-02** | Only the current vault expands | The tree is shown only under the current vault, only when its status is `active`, and only when the Workspace page supplied `tree` (not null). At most one vault is expanded at a time. | Given vault A is current and active on the Workspace page, then A's tree is visible under A and no other vault shows a tree. |
| **FR-03** | Clicking a non-current vault opens it | Clicking a non-current, non-missing vault row runs the existing `router.post(open.url(uuid))`. The server redirects to Workspace, and that vault then shows expanded. | Given vault B is not current, when the user clicks B, then B becomes current, the Workspace loads, and B's tree is expanded. |
| **FR-04** | Clicking the current vault | On the Workspace page with tree data, clicking the current vault row toggles its expanded state. On any other page, clicking it goes to Workspace (`router.visit(workspace())`) with the vault expanded. In icon mode (desktop), clicking only navigates to Workspace if not already there and never toggles. | Given Workspace with A expanded, clicking A collapses it and clicking again expands it. Given the Settings page, clicking A goes to Workspace and A's tree is expanded. |
| **FR-05** | Active vault row actions | When the current vault's tree data is available, the row shows three `SidebarMenuAction` icons: **New note** (FilePlus) opens `CreateNoteDialog` at the vault root; **New folder** (FolderPlus) opens `CreateFolderDialog` at the root; **Refresh** (RefreshCw) posts `vaults.reindex`, is disabled and spinning while in flight, and preserves scroll. Each has a `title` and `aria-label` ("New note", "New folder", "Re-index"). The actions work whether the vault row is expanded or collapsed. | Given the Workspace with active vault A, when the user clicks New note on A's row (expanded or collapsed), then the create-note dialog opens with the root folder selected. Clicking Refresh re-indexes and the tree updates. |
| **FR-06** | Existing per-item actions preserved | Folder rows keep their menu: new note here, new folder here, delete folder. Note rows keep theirs: rename, move to, delete. All existing dialogs work from the sidebar. | Given a folder in the sidebar tree, when the user opens its menu and picks "New note here", then `CreateNoteDialog` opens with that folder as the default. |
| **FR-07** | Notes panel removed | `Workspace.vue` no longer renders the `aside` Notes panel or the `PanelLeft` toggle and its `treeOpen` state. The editor `main` area fills the available width. The vault header bar (name, path, status badge, Close vault) stays. The empty-state copy becomes "Select a note from the sidebar, or create a new one." | Given the Workspace with an active vault, then there is no "Notes" heading or toggle button in the page content, and the editor spans the full content width. |
| **FR-08** | Mobile via the sidebar sheet | On mobile (`isMobile`) the tree appears inside the sidebar sheet. Selecting a note closes the sheet (`setOpenMobile(false)`). The note and folder menu buttons are visible without hover below `md`. | Given a phone-width viewport, when the user opens the sheet and taps a note, then the note opens and the sheet closes. |
| **FR-09** | Icon mode hides trees | When the sidebar is collapsed to icon mode, the tree container, the chevron and the row actions are hidden. Vault icons with tooltips remain. | Given the sidebar collapsed to icons, then no tree items, chevrons or row actions are rendered visibly. |
| **FR-10** | Missing vaults | A vault with status `missing` shows `TriangleAlert` and "(folder missing)" for screen readers, has no chevron and cannot expand. Clicking it keeps today's behaviour (`router.post(open.url(uuid))`). | Given the current vault is missing, then its row shows TriangleAlert, no chevron and no tree. The Workspace alert for the missing vault is unchanged. |
| **FR-11** | Selected note highlight | The note matching `page.props.note?.uuid` is highlighted (`bg-sidebar-accent`, `aria-current="page"`). The highlight updates after a partial `only: ['note']` reload. Folders that contain the open note start expanded (`node.open`). | Given note X is open, then X's row in the sidebar is highlighted. When the user clicks note Y, Y is highlighted and X is not. |
| **FR-12** | External changes keep working | Tree signature polling (`useExternalChanges` in `Workspace.vue`) and its `router.reload({ only: ['tree','folders','treeSignature'] })` must update the sidebar tree without a full reload. | Given the Workspace is open, when a `.md` file is added to the vault on disk, then within the polling interval it appears in the sidebar tree. |
| **FR-13** | No tree work off the Workspace page | Tree data stays a Workspace page prop. Other pages (Settings, Vaults) do not build or return `tree`, `folders` or `treeSignature`. | Given a current active vault, when GET `settings/general` or `vaults` runs, then the Inertia props do not include `tree`, `folders` or `treeSignature`. |

## 5. Non-Functional Requirements
- **Security & Authorization**: no change. This is a local app with no authentication (ADR `local-app-without-authentication`). No paths or filesystem logic in Vue.
- **Performance**: the vault directory scan (`VaultIndexService::browse`) must run only for Workspace and `notes.show` renders, as it does today. It must not run on every Inertia request.
- **Accessibility & UX**:
  - Rows and actions are keyboard reachable.
  - Each action has an `aria-label`.
  - The chevron state is announced through `aria-expanded` on the current vault's button.
  - The selected note carries `aria-current="page"`.
  - Every Vue component has a single root element.
  - Use sidebar colour tokens (`sidebar-accent`, `sidebar-foreground`) inside the sidebar.
- **Reliability & Data Integrity**: no data path changes. Existing dialogs and endpoints are reused as they are.

## 6. Technical Constraints & Context
- Framework: Laravel 13 (PHP 8.4); Inertia v3 + Vue 3 + Tailwind CSS v4 + shadcn-vue sidebar (`resources/js/components/ui/sidebar`).
- Routing: Laravel Wayfinder (`@/routes/vaults` `open`, `reindex`; `@/routes` `workspace`; `@/routes/notes` `show`).
- Testing: Pest 5; frontend type check with `vue-tsc`; JS tests with `vp test`.
- Code style: Pint (`vendor/bin/pint --dirty --format agent`).
- Layout: `AppLayout` is a persistent layout (set through `createInertiaApp({ layout })`), so `AppSidebar` keeps its local UI state across Inertia visits.
- Rules that apply (CLAUDE.md §6): Vue does presentation only (Rule 5 / Master Plan §42); no new tables; no sync.

## 7. Risks & Assumptions
- **Assumption**: no tree on non-Workspace pages is acceptable, because clicking the current vault goes back to Workspace (see ADR `sidebar-note-tree-data-source`).
- **Assumption**: undeclared page props fall through as attributes on the single root element. `Workspace.vue` therefore keeps `tree`, `folders` and `canTrash` declared even though it no longer renders them.
- **Risk**: three absolutely positioned `SidebarMenuAction`s overlap the chevron or name. **Mitigation**: offset the actions (`right-1`, `right-7`, `right-13`), give the button `pr-20` when actions render, and use `showOnHover` (always visible on mobile and on focus).
- **Risk**: dialogs inside the mobile sheet are unmounted if the sheet closes while a dialog is open. **Mitigation**: the sheet closes only on note link selection, never on dialog actions.
- **Risk**: hiding the tree with an unmounting Collapsible would also unmount the dialog host, breaking the row actions while collapsed. **Mitigation**: keep `NoteTree` mounted and hide its list with `v-show`.

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified (missing vault, icon mode, mobile, non-Workspace pages, external changes)
- [x] Approved to proceed to Planning (`plan.md`)
