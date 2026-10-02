# Decision: Sidebar Note Tree Data Source

- **Status**: Accepted
- **Date**: 2026-10-03
- **Feature**: `sidebar-vault-tree` (Level 3)
- **Master Plan**: §36 Main Application Layout, §42 Vue Architecture Rules, §52 Phase 3, §54 Phase 5

## Context
The note tree moves from the Workspace page panel into the app sidebar, which is rendered on every page through the persistent `AppLayout`. Tree data (`tree`, `folders`, `treeSignature`, `canTrash`) is currently built by `WorkspaceController` through `VaultIndexService::browse()`. That method does a recursive directory scan of the vault plus a notes query. `useExternalChanges` (in `Workspace.vue`, tied to the editor) polls for changes and refreshes the tree with `router.reload({ only: ['tree','folders','treeSignature'] })`. Note links use partial `only: ['note']` reloads. No new endpoints are allowed.

## Options Considered
1. **Shared closure prop in `HandleInertiaRequests`.** The tree is available on every page. Cost: a full vault scan on every Inertia request, including Settings saves and redirects after a POST. The tree on non-Workspace pages is never refreshed by polling, and props would be duplicated with the Workspace page.
2. **Shared `Inertia::defer` prop.** Avoids blocking first render, but adds a second request on every page load and a loading flash in the sidebar.
3. **Shared `Inertia::optional` prop**, loaded by a client partial reload on non-Workspace pages. Pays the scan cost only when needed, but adds client orchestration and a staleness policy, for a view (tree on Settings) of low value.
4. **`Inertia::once` prop.** Cheap after the first load, but stale after create, rename, move, delete or re-index, with no clean way to invalidate it.
5. **Page-scoped: keep the tree as a Workspace page prop; the sidebar reads `usePage()` and renders the tree only when `page.component === 'Workspace'`.** No backend change, no extra cost, and polling and partial reloads keep working unchanged. The tree is not visible on other pages.

## Decision
Option 5. The sidebar shows the current vault's tree only on the Workspace page. On other pages the current vault row is collapsed, and clicking it goes to Workspace, where it opens expanded. Other vaults never show trees; clicking one opens it through the existing `vaults.open`.

## Consequences
- **Positive**:
  - Zero backend or middleware change.
  - The scan runs only where it did before.
  - The external-change detection contract (ADR `external-change-detection`) is unchanged.
  - The Vue side stays presentation only: a read-only `useWorkspaceTree` composable selects props.
- **Negative**: no tree on Settings or Vaults pages; it costs one click to return.
- **Constraints introduced**:
  - `Workspace.vue` must keep `tree`, `folders` and `canTrash` declared as props even though it does not render them, so they do not fall through as attributes.
  - Any future page that wants the tree must either be the Workspace component, or this decision must be revisited (Option 3 is the preferred follow-up).
