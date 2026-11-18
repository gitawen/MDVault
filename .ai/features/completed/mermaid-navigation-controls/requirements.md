# Requirements: Mermaid Navigation Controls

## Metadata
- **Feature Name**: Mermaid Navigation Controls
- **Feature ID**: feat-mermaid-nav-controls
- **Author**: System Analyst
- **Created Date**: 2026-11-18
- **Task Complexity**: Level 3 — Complex Development
- **Status**: APPROVED

---

## 1. Problem Statement
MDVault's rendered Markdown preview currently places all Mermaid diagram actions in a single top-right floating `.mermaid-toolbar`: `[Toggle pan] [Zoom out] [Zoom in] [Reset view] | [Copy source] [Copy SVG] [Edit diagram]`. Adding the requested 3×3 directional navigation D-pad widget at the bottom-right of each rendered Mermaid diagram—which includes `Reset view` at its center along with `Zoom in` and `Zoom out`—would duplicate controls already present in `.mermaid-toolbar`. Additionally, users lack directional pan buttons and a dedicated fullscreen inspection modal for complex diagrams.

We need to rebuild the Mermaid preview controls into two non-redundant groups (a top-right utility toolbar and a bottom-right 3×3 navigation D-pad) and add a fullscreen diagram viewer modal with pan, zoom, percentage indicator, and reset controls.

---

## 2. Goals & Non-Goals
### In Scope (Goals)
- Split inline `.mermaid-block` controls cleanly with zero button redundancy:
  - **Top-right utility toolbar (`.mermaid-toolbar`)**: `[Toggle pan] | [Copy diagram source] [Copy SVG] [Edit diagram]`.
  - **Bottom-right 3×3 navigation D-pad (`.mermaid-nav`)**:
    - Row 1: `[Open fullscreen]` `[Pan up]` `[Zoom in]`
    - Row 2: `[Pan left]` `[Reset view]` `[Pan right]`
    - Row 3: `(empty spacer)` `[Pan down]` `[Zoom out]`
- Add directional pan primitives (`MERMAID_PAN_STEP`, `stepMermaidPan`, `panMermaidBlock`) and zoom percentage formatter (`formatMermaidZoomPercent`) to `mermaidPanZoom.ts`.
- Add clean SVG extraction (`getCleanMermaidSvg`) and render-state attribute (`data-mermaid-rendered="true"`) in `mermaidRenderer.ts` so the D-pad is only displayed when a diagram SVG has successfully rendered (not during loading or syntax error states).
- Add a fullscreen Mermaid viewer modal (`MermaidFullscreenDialog.vue`) supporting pointer drag-to-pan, mouse wheel zoom, zoom out/in buttons, live zoom percentage indicator (`100%`), reset view button, close (`X`) button, `Escape` key dismissal, backdrop click dismissal, and dark/light theme sync.
- Update and expand unit tests in `tests/js/editor/markdownPreview.test.ts`, `tests/js/editor/mermaidPanZoom.test.ts`, and `tests/js/editor/mermaid.test.ts`.

### Out of Scope (Non-Goals)
- Modifying the diagram editor modal (`resources/js/components/editor/MermaidDialog.vue`), which uses a different side-by-side layout with no 3×3 D-pad and no control redundancy.
- Adding new npm or Composer dependencies (e.g., `jsdom`, `@vue/test-utils`, or external pan/zoom libraries).
- Exporting diagrams to PNG/PDF or adding persistent per-note viewport storage across reloads.
- Changing backend PHP routes, controllers, or database schema.

---

## 3. User Personas & Stories
- **As a** note author or reader viewing a Markdown note in Split or Preview mode,
  **I want to** navigate large Mermaid diagrams using an intuitive 3×3 D-pad (pan up/down/left/right, zoom in/out, reset view, and open fullscreen) while keeping diagram actions (toggle drag-pan, copy source, copy SVG, edit) in the top-right toolbar without duplicate buttons,
  **So that** I can inspect intricate diagrams effortlessly without cluttered or redundant UI controls.
- **As a** user inspecting a dense flowchart, sequence diagram, or ER diagram,
  **I want to** open the diagram in a fullscreen modal overlay with free drag-panning, wheel/button zooming, a live zoom percentage readout, and one-click reset,
  **So that** I can read fine details comfortably on any screen size and dismiss the overlay with `Close (X)`, `Escape`, or clicking the backdrop.

---

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | Non-redundant top-right utility toolbar | `.mermaid-toolbar` in `markdownPreview.ts` contains only diagram utilities: `Toggle pan` (`data-mermaid-pan`, `aria-pressed="false"`), a vertical divider, `Copy diagram source` (`data-mermaid-copy`), `Copy SVG` (`data-mermaid-copy-svg`), and `Edit diagram` (`data-mermaid-edit`). It must NOT render `Zoom out`, `Zoom in`, `Reset view`, directional pan, or fullscreen buttons. | Given a Markdown document with a `mermaid` code block, When `renderMarkdownPreview` outputs HTML, Then `.mermaid-toolbar` contains exactly the 4 utility buttons (`data-mermaid-pan`, `data-mermaid-copy`, `data-mermaid-copy-svg`, `data-mermaid-edit`) and none of the navigation/zoom/reset controls. |
| **FR-02** | Bottom-right 3×3 navigation D-pad | `.mermaid-nav` inside `.mermaid-block` renders a 3×3 CSS Grid (`grid-cols-3`) positioned at `bottom-2.5 right-2.5` with cells in exact row-major order: Row 1 = `Open fullscreen` (`data-mermaid-fullscreen`), `Pan up` (`data-mermaid-pan-up`), `Zoom in` (`data-mermaid-zoom-in`); Row 2 = `Pan left` (`data-mermaid-pan-left`), `Reset view` (`data-mermaid-reset`), `Pan right` (`data-mermaid-pan-right`); Row 3 = non-interactive empty spacer (`aria-hidden="true"`), `Pan down` (`data-mermaid-pan-down`), `Zoom out` (`data-mermaid-zoom-out`). | Given a rendered Mermaid block, When inspecting `.mermaid-nav`, Then it has 8 `<button type="button">` elements and 1 empty spacer in the exact 3×3 order, and each `data-mermaid-*` attribute appears once across the entire `.mermaid-block`. |
| **FR-03** | Directional pan & viewport calculations | `mermaidPanZoom.ts` exports `MERMAID_PAN_STEP` (`40`), `stepMermaidPan(viewport, dx, dy)`, `panMermaidBlock(target, dx, dy)`, and `formatMermaidZoomPercent(zoom)`. Clicking D-pad directional buttons pans the viewport along camera/scroll semantics without requiring `Toggle pan` drag-mode to be active: Pan up increases `y` (`+40`), Pan down decreases `y` (`-40`), Pan left increases `x` (`+40`), Pan right decreases `x` (`-40`). | Given a rendered `.mermaid-block`, When the user clicks `Pan up`, `Pan down`, `Pan left`, or `Pan right`, Then the SVG `transform` updates smoothly by `±40px` along the respective axis while preserving current `zoom`, and `Reset view` restores `(x: 0, y: 0, zoom: 1)`. |
| **FR-04** | Render-state gating & non-overlapping layout | `mermaidRenderer.ts` sets `data-mermaid-rendered="true"` on `.mermaid-block` after SVG render succeeds and removes it on empty source or syntax error. CSS hides `.mermaid-nav` unless `data-mermaid-rendered="true"` and enforces a minimum rendered block height (`9.75rem` / `156px`) so `.mermaid-toolbar` and `.mermaid-nav` never overlap vertically on compact diagrams. | Given a Mermaid block that is loading or has a syntax error, When preview renders, Then `.mermaid-nav` is hidden. Given a short single-row rendered diagram (e.g. `A --> B`), When hovering the block, Then `.mermaid-toolbar` and `.mermaid-nav` do not collide or overlap. |
| **FR-05** | Fullscreen modal trigger & clean SVG handoff | Clicking `Open fullscreen` (`button[data-mermaid-fullscreen]`) in `MarkdownEditor.vue` extracts a clean, un-transformed SVG via `getCleanMermaidSvg(block, isDark)` (preferring pristine `svgCache`, falling back to cloning the DOM SVG with inline `transform`/`transformOrigin`/`transition` cleared) and opens `MermaidFullscreenDialog.vue` with viewport reset to `(x: 0, y: 0, zoom: 1)`. | Given a rendered Mermaid block that was zoomed/panned inline, When the user clicks `Open fullscreen`, Then `MermaidFullscreenDialog` opens displaying the diagram centered at `100%` without inheriting the inline pan/zoom offset. |
| **FR-06** | Fullscreen modal interactive controls & dismissal | `MermaidFullscreenDialog.vue` renders a fullscreen overlay dialog (`reka-ui` `Dialog`) with: top-left controls (`[Zoom out]`, live `[100%]` zoom indicator formatted via `formatMermaidZoomPercent`, `[Zoom in]`, `[Reset]`); top-right `[Close (X)]` button; immediate drag-to-pan on the canvas (cursor `grab`/`grabbing`); mouse wheel zoom; keyboard shortcuts (`+`/`=` zoom in, `-` zoom out, `0` reset, `Escape` close); backdrop click close; and reactive re-render if `isDark` changes while open. | Given the fullscreen modal is open, When the user zooms in/out via buttons or wheel, Then the zoom indicator updates (`120%`, `80%`, clamped to `25%`–`400%`). When dragging the canvas, Then the diagram pans smoothly. When clicking `Reset`, Then zoom returns to `100%` and pan resets to `(0, 0)`. When clicking `Close (X)`, pressing `Escape`, or clicking the backdrop, Then the modal closes. |

---

## 5. Non-Functional Requirements
- **Security & Authorization**: Existing strict Mermaid security (`securityLevel: 'strict'`) and HTML escaping (`escapeAttribute`, `escapeHtml`) in `markdownPreview.ts` and `mermaidRenderer.ts` remain untouched; no untrusted attributes or scripts are introduced.
- **Performance**: Opening fullscreen reuses `svgCache` via `getCleanMermaidSvg` for instantaneous O(1) display without redundant Mermaid re-renders unless `isDark` changes while open. Transitions disable (`transition: none`) during active pointer dragging for 60fps responsiveness.
- **Accessibility & UX**:
  - Every icon-only button has explicit `type="button"`, `aria-label`, and `title` attributes; `Toggle pan` tracks `aria-pressed`.
  - `.mermaid-action-btn:focus-visible` displays a clear focus ring; `.mermaid-nav` follows `focus-within:opacity-100` for keyboard Tab discoverability and `@media (hover: none)` fallback for touch devices.
  - `MermaidFullscreenDialog.vue` maintains Vue's single-root element requirement (`<Dialog>` root) and includes screen-reader `<DialogTitle>` and `<DialogDescription>` for `reka-ui` accessibility compliance.
  - Both light and dark themes use existing semantic CSS variables (`--background`, `--foreground`, `--border`, `--muted`, `--accent`, `--primary`).
- **Reliability & Data Integrity**: Switching notes (`noteUuid` / `noteSelectionTick`) or switching to `code` view closes any open fullscreen modal cleanly; async theme re-renders inside `MermaidFullscreenDialog` guard against stale updates after close or subsequent changes.

---

## 6. Technical Constraints & Context
- Framework: Laravel 13 (PHP 8.4)
- Frontend: Inertia.js v3 + Vue 3.5 + Tailwind CSS v4 + `reka-ui` (`Dialog`) + `@lucide/vue`
- Bundler / Test Runner / Linter: `vite-plus` (`vp test run` in `environment: 'node'`, `vp check`, `vue-tsc --noEmit`)
- Existing JS test suite runs in Node (`vite.config.ts`: `environment: 'node'`) without `jsdom` or `@vue/test-utils`; pure logic and DOM-mockable helpers must remain Node-safe in `resources/js/lib/editor/*.ts`.

---

## 7. Risks & Assumptions
- **Assumption 1**: `MermaidDialog.vue` (the source-editing modal) does not include a D-pad and is intentionally left unchanged.
- **Assumption 2**: `MarkdownEditor.vue` is the sole consumer of `renderMarkdownPreview` and `.mermaid-block` DOM interactions in the application.
- **Risk 1 & Mitigation**: `DialogContent.vue` enforces `max-w-[calc(100%-2rem)] sm:max-w-lg` by default; if not overridden with both `max-w-none` and `sm:max-w-none`, the fullscreen modal would shrink to 512px on desktop. Mitigation: explicitly pass `max-w-none sm:max-w-none` in `MermaidFullscreenDialog.vue`.
- **Risk 2 & Mitigation**: Short Mermaid diagrams (e.g., `graph LR\nA-->B`) could allow top-right `.mermaid-toolbar` and bottom-right `.mermaid-nav` to overlap vertically. Mitigation: use compact `size-6` (`1.5rem`) buttons inside `.mermaid-nav` and set `min-height: 9.75rem` on `.mermaid-block[data-mermaid-rendered='true']`.
- **Risk 3 & Mitigation**: Copying `.mermaid-container svg` `outerHTML` directly from a panned/zoomed block would carry inline CSS `transform` styles into fullscreen. Mitigation: implement `getCleanMermaidSvg` preferring pristine `svgCache` and stripping inline transform styles on fallback clone.

---

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified
- [x] Approved to proceed to Planning (`plan.md`)
