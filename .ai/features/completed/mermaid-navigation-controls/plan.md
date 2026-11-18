# Plan: Mermaid Navigation Controls

## Metadata
- **Feature Name**: Mermaid Navigation Controls
- **Feature ID**: feat-mermaid-nav-controls
- **Author**: System Analyst
- **Created Date**: 2026-11-18
- **Task Complexity**: Level 3 — Complex Development
- **Requirements**: `requirements.md`
- **Status**: APPROVED

---

## 1. Summary
We will split MDVault's Mermaid preview controls into a non-redundant top-right utility toolbar (`.mermaid-toolbar`: Toggle pan, Copy source, Copy SVG, Edit diagram) and a bottom-right 3×3 directional D-pad (`.mermaid-nav`: Open fullscreen, Pan up/left/right/down, Reset view, Zoom in/out). We will extend `mermaidPanZoom.ts` and `mermaidRenderer.ts` with pure directional pan, zoom percentage formatting, render-state tracking, and clean SVG extraction helpers, and create `MermaidFullscreenDialog.vue` using MDVault's existing `reka-ui` `Dialog` primitives for interactive fullscreen inspection.

---

## 2. Architecture & Design
- **Approach**:
  - Keep all Mermaid HTML generation in `markdownPreview.ts` and all pan/zoom math and state management in `mermaidPanZoom.ts` so logic remains Node-testable under `vp test run` (`environment: 'node'`).
  - Gate `.mermaid-nav` visibility via `data-mermaid-rendered="true"` set by `mermaidRenderer.ts` and ensure vertical clearance (`min-height: 9.75rem` on rendered blocks, compact `1.5rem` D-pad buttons) so the top-right and bottom-right overlays never collide.
  - Build `MermaidFullscreenDialog.vue` atop `Dialog` / `DialogContent` (`max-w-none sm:max-w-none`, `:show-close-button="false"`) so `reka-ui` handles focus trapping, `aria-modal`, `Escape` dismissal, and `DialogOverlay` backdrop click dismissal natively, while Vue reactive `:style` drives `transform` on the SVG wrapper.
- **Alternatives Considered**:
  - *Mounting a Vue component inside every `.mermaid-block` via `createApp` / portals*: Rejected — unnecessary lifecycle complexity given `MarkdownEditor.vue` already uses fast delegated click handling (`handlePreviewClick`) on `v-html` output.
  - *True `100vw × 100vh` edge-to-edge modal without `DialogOverlay` margin*: Rejected — leaves no clickable backdrop outside the canvas, risking accidental modal dismissal when users click/drag empty canvas space to pan. Using `w-[calc(100vw-2rem)] h-[calc(100dvh-2rem)]` (and `3rem` inset on `sm+`) fills nearly the entire screen while preserving unambiguous backdrop-click dismissal via `DialogOverlay`.
  - *Replacing `MermaidDialog.vue` toolbar with D-pad*: Rejected — `MermaidDialog.vue` is the edit modal with no 3×3 D-pad or fullscreen toggle and has no redundancy.
- **Decision Records**: None (Level 3 refinement within existing `mermaidPanZoom` + `reka-ui` Dialog architecture).

### Data Model Changes
None (client-side UI/editor feature only).

### Backend Components
None.

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Lib (Preview) | `resources/js/lib/editor/markdownPreview.ts` | Export directional/fullscreen Lucide SVG constants; split `.mermaid-block` HTML into non-redundant `.mermaid-toolbar` (4 actions) and `.mermaid-nav` 3×3 D-pad (8 buttons + 1 spacer). |
| Lib (Pan/Zoom) | `resources/js/lib/editor/mermaidPanZoom.ts` | Add `MERMAID_PAN_STEP`, `stepMermaidPan`, `formatMermaidZoomPercent`, and `panMermaidBlock` helpers. |
| Lib (Renderer) | `resources/js/lib/editor/mermaidRenderer.ts` | Set/clear `data-mermaid-rendered="true"` on `.mermaid-block`; export `getCleanMermaidSvg` for fullscreen handoff. |
| Component (Modal) | `resources/js/components/editor/MermaidFullscreenDialog.vue` | Fullscreen `Dialog` overlay with pan/zoom canvas, top-left `[Zoom out] [100%] [Zoom in] [Reset]` controls, top-right `[Close (X)]`, keyboard shortcuts, and dark-mode re-rendering. |
| Component (Editor) | `resources/js/components/editor/MarkdownEditor.vue` | Delegate clicks for fullscreen and 4 directional pan buttons; pass clean SVG to `MermaidFullscreenDialog`; add `.mermaid-nav`, rendered min-height, focus-visible, active-pan, and `@media (hover: none)` styles. |

### Routes
None.

---

## 3. Implementation Tasks

- [ ] **T1 — Extend `mermaidPanZoom.ts` with directional pan & zoom percentage helpers**
  - Files: `resources/js/lib/editor/mermaidPanZoom.ts`
  - Details:
    - Export `MERMAID_PAN_STEP = 40`.
    - Export `stepMermaidPan(viewport: MermaidViewport, dx: number, dy: number): MermaidViewport` (pure, Node-safe; guards non-finite `viewport.x`, `viewport.y`, `dx`, `dy` with `0` while preserving `zoom`).
    - Export `formatMermaidZoomPercent(zoom: number): string` (pure, Node-safe; passes `zoom` through `clampMermaidZoom(zoom)` and returns `${Math.round(clamped * 100)}%`).
    - Export `panMermaidBlock(target: HTMLElement, dx: number, dy: number): MermaidViewport` (reads `getMermaidViewport(target)`, calls `stepMermaidPan`, applies transform via `applyMermaidTransform`, returns updated viewport).
  - Covers: FR-03, FR-06

- [ ] **T2 — Update `mermaidRenderer.ts` with render-state attribute and clean SVG extraction**
  - Files: `resources/js/lib/editor/mermaidRenderer.ts`
  - Details:
    - Import `resolveMermaidSvg` from `./mermaidPanZoom`.
    - In `renderMermaidInContainer`: on successful render set `element.dataset.mermaidRendered = 'true'`; when source is empty or rendering throws a syntax error, call `delete element.dataset.mermaidRendered`.
    - Export `getCleanMermaidSvg(block: HTMLElement, isDark: boolean): string`:
      - Read `source` from `block.dataset?.mermaidSource ?? block.querySelector?.('.mermaid-raw code')?.textContent ?? ''`.
      - If `source.trim()` exists in `svgCache` via `getSvgCacheKey(source, isDark)`, return that cached pristine SVG string.
      - Otherwise resolve `svg = resolveMermaidSvg(block)`; if found, clone it (`svg.cloneNode?.(true) as SVGElement`), clear `clone.style.transform = ''`, `clone.style.transformOrigin = ''`, `clone.style.transition = ''` (guarding `clone.style` existence), and return `clone.outerHTML ?? ''`. Return `''` if unavailable.
  - Covers: FR-04, FR-05

- [ ] **T3 — Rebuild `.mermaid-toolbar` and add `.mermaid-nav` 3×3 D-pad in `markdownPreview.ts`**
  - Files: `resources/js/lib/editor/markdownPreview.ts`
  - Details:
    - Export new 14×14 Lucide SVG string constants matching existing icon attributes (`width="14" height="14" viewBox="0 0 24 24" ...`):
      - `MERMAID_FULLSCREEN_ICON` (`lucide-maximize-2`)
      - `MERMAID_PAN_UP_ICON` (`lucide-chevron-up`)
      - `MERMAID_PAN_DOWN_ICON` (`lucide-chevron-down`)
      - `MERMAID_PAN_LEFT_ICON` (`lucide-chevron-left`)
      - `MERMAID_PAN_RIGHT_ICON` (`lucide-chevron-right`)
    - Keep `MERMAID_PAN_ICON`, `MERMAID_ZOOM_OUT_ICON`, `MERMAID_ZOOM_IN_ICON`, `MERMAID_RESET_ICON`, `MERMAID_SVG_ICON`, `MERMAID_EDIT_ICON` exported.
    - Update `code()` renderer for `langClass?.toLowerCase() === 'mermaid'`:
      - `.mermaid-toolbar` (`top-2.5 right-2.5`): contains ONLY:
        1. `button[data-mermaid-pan]` (`aria-label="Toggle pan"`, `title="Toggle pan"`, `aria-pressed="false"`)
        2. Divider `<span class="w-px h-4 bg-border/60 mx-0.5" aria-hidden="true"></span>`
        3. `button[data-mermaid-copy]` (`aria-label="Copy diagram source"`, `title="Copy diagram source"`)
        4. `button[data-mermaid-copy-svg]` (`aria-label="Copy SVG"`, `title="Copy SVG"`)
        5. `button[data-mermaid-edit]` (`aria-label="Edit diagram"`, `title="Edit diagram"`)
      - Add `.mermaid-nav` sibling inside `.mermaid-block` (`absolute bottom-2.5 right-2.5 grid grid-cols-3 gap-0.5 rounded-lg border border-border/70 bg-background/90 p-1 shadow-xs backdrop-blur-xs opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity z-10`) containing 9 grid children in exact row-major order:
        - Row 1: `button[data-mermaid-fullscreen]` (`Open fullscreen`), `button[data-mermaid-pan-up]` (`Pan up`), `button[data-mermaid-zoom-in]` (`Zoom in`)
        - Row 2: `button[data-mermaid-pan-left]` (`Pan left`), `button[data-mermaid-reset]` (`Reset view`), `button[data-mermaid-pan-right]` (`Pan right`)
        - Row 3: `<span class="mermaid-nav-spacer" aria-hidden="true"></span>`, `button[data-mermaid-pan-down]` (`Pan down`), `button[data-mermaid-zoom-out]` (`Zoom out`)
      - Every D-pad button uses `type="button"` and `class="mermaid-action-btn mermaid-nav-btn"` with explicit `aria-label` and `title`.
  - Covers: FR-01, FR-02

- [ ] **T4 — Create `MermaidFullscreenDialog.vue`**
  - Files: `resources/js/components/editor/MermaidFullscreenDialog.vue`
  - Details:
    - Single-root Vue 3 SFC wrapping `<Dialog v-model:open="open">`.
    - Props: `source?: string`, `initialSvg?: string`, `isDark?: boolean`.
    - Model: `open = defineModel<boolean>('open', { default: false })`.
    - `<DialogContent :show-close-button="false" class="flex h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-none flex-col gap-0 overflow-hidden p-0 sm:h-[calc(100dvh-3rem)] sm:w-[calc(100vw-3rem)] sm:max-w-none" @keydown="handleDialogKeydown">`.
    - Screen-reader `<DialogHeader class="sr-only">` with `<DialogTitle>` and `<DialogDescription>`.
    - Top-left floating control group (`absolute top-3 left-3 z-20 flex items-center gap-1 rounded-lg border border-border/70 bg-background/90 p-1 shadow-sm backdrop-blur-xs`):
      - `[Zoom out]` (`ZoomOut` icon, `data-fullscreen-zoom-out`, `aria-label="Zoom out"`)
      - Zoom indicator (`data-fullscreen-zoom-indicator`, `aria-label="Zoom level"`, `tabular-nums`, displays `formatMermaidZoomPercent(viewport.zoom)`)
      - `[Zoom in]` (`ZoomIn` icon, `data-fullscreen-zoom-in`, `aria-label="Zoom in"`)
      - Vertical divider
      - `[Reset]` (`RotateCcw` icon + `Reset` text, `data-fullscreen-reset`, `aria-label="Reset view"`)
    - Top-right floating close button (`absolute top-3 right-3 z-20`): `<DialogClose as-child>` wrapping `<Button variant="outline" size="icon" data-fullscreen-close aria-label="Close fullscreen" title="Close (Esc)"><X class="size-4" /></Button>`.
    - Interactive pan/zoom canvas (`flex min-h-0 flex-1 items-center justify-center overflow-hidden bg-card/40 p-6 select-none`, `style="touch-action: none"`, `cursor-grab` / `cursor-grabbing` when dragging):
      - Pointer drag (`pointerdown` on `event.button === 0`, `pointermove`, `pointerup`, `pointercancel`, `setPointerCapture`/`releasePointerCapture` in `try/catch`).
      - Wheel zoom (`@wheel`, skipping `event.deltaY === 0`, stepping `±MERMAID_ZOOM_STEP`).
      - Keyboard shortcuts (`+`/`=`: zoom in; `-`: zoom out; `0`: reset; Arrow keys when target is not a button/input: pan via `stepMermaidPan` by `±MERMAID_PAN_STEP`; `Escape` handled by `reka-ui`).
      - SVG wrapper `<div class="mermaid-fullscreen-svg flex max-h-full max-w-full items-center justify-center overflow-visible" :style="svgWrapperStyle" v-html="renderedSvg" />` with `<style>` rule `.mermaid-fullscreen-svg svg { max-width: 100%; max-height: calc(100dvh - 8rem); height: auto; display: block; }`.
    - On `open` becoming `true`: reset `viewport` to `{ ...DEFAULT_MERMAID_VIEWPORT }`, set `renderedSvg` from `props.initialSvg` (or asynchronously render via `renderMermaidDiagram` if `initialSvg` is empty). On `props.isDark` changing while open: re-render with stale-request counter guard.
  - Covers: FR-05, FR-06

- [ ] **T5 — Wire D-pad actions, fullscreen modal, and CSS into `MarkdownEditor.vue`**
  - Files: `resources/js/components/editor/MarkdownEditor.vue`
  - Details:
    - Import `MermaidFullscreenDialog` from `@/components/editor/MermaidFullscreenDialog.vue`.
    - Import `MERMAID_PAN_STEP`, `panMermaidBlock` from `@/lib/editor/mermaidPanZoom`, and `getCleanMermaidSvg` from `@/lib/editor/mermaidRenderer`.
    - Add refs: `fullscreenOpen = ref(false)`, `fullscreenSource = ref('')`, `fullscreenSvg = ref('')`.
    - Close `fullscreenOpen` if `noteUuid`, `noteSelectionTick`, or `displayMode === 'code'` changes.
    - In `handlePreviewClick`:
      - Add `button[data-mermaid-fullscreen]` handler: check `.mermaid-block` and `.mermaid-container svg` exist; populate `fullscreenSource` and `fullscreenSvg = getCleanMermaidSvg(block, isDark.value)`; set `fullscreenOpen.value = true`.
      - Add `button[data-mermaid-pan-up]` -> `panMermaidBlock(block, 0, MERMAID_PAN_STEP)`
      - Add `button[data-mermaid-pan-down]` -> `panMermaidBlock(block, 0, -MERMAID_PAN_STEP)`
      - Add `button[data-mermaid-pan-left]` -> `panMermaidBlock(block, MERMAID_PAN_STEP, 0)`
      - Add `button[data-mermaid-pan-right]` -> `panMermaidBlock(block, -MERMAID_PAN_STEP, 0)`
      - Keep `data-mermaid-pan`, `data-mermaid-zoom-out`, `data-mermaid-zoom-in`, `data-mermaid-reset`, `data-mermaid-copy`, `data-mermaid-copy-svg` (optionally using `getCleanMermaidSvg(block, isDark.value) || svgEl.outerHTML` when `svgEl` exists), `data-mermaid-edit`, `data-mermaid-toggle-source`.
    - Mount `<MermaidFullscreenDialog v-model:open="fullscreenOpen" :source="fullscreenSource" :initial-svg="fullscreenSvg" :is-dark="isDark" />` inside the root `<div>` of `<template>`.
    - Update `<style>` section:
      - Add `.mermaid-action-btn:focus-visible { outline: 2px solid var(--ring); outline-offset: 1px; }`.
      - Add `.mermaid-nav-btn { height: 1.5rem; width: 1.5rem; }`.
      - Add `.mermaid-block[data-mermaid-rendered='true'] { min-height: 9.75rem; }` and `.mermaid-block[data-mermaid-rendered='true'] .mermaid-container { min-height: 7.5rem; }`.
      - Add `.mermaid-block:not([data-mermaid-rendered='true']) .mermaid-nav { display: none; }`.
      - Add `.mermaid-block[data-pan-active='true'] .mermaid-toolbar { opacity: 1; }`.
      - Add `@media (hover: none) { .mermaid-toolbar, .mermaid-nav { opacity: 1; } }`.
  - Covers: FR-03, FR-04, FR-05, FR-06

- [ ] **T6 — Update and add JS tests**
  - Files:
    - `tests/js/editor/markdownPreview.test.ts`
    - `tests/js/editor/mermaidPanZoom.test.ts`
    - `tests/js/editor/mermaid.test.ts`
  - Details:
    - `markdownPreview.test.ts`:
      - Update the Mermaid button test to verify non-redundant split: `.mermaid-toolbar` contains ONLY `data-mermaid-pan`, `data-mermaid-copy`, `data-mermaid-copy-svg`, `data-mermaid-edit` (and does NOT contain `data-mermaid-zoom-in`, `data-mermaid-zoom-out`, `data-mermaid-reset`, `data-mermaid-fullscreen`, `data-mermaid-pan-up/down/left/right`).
      - Verify `.mermaid-nav` contains the 3×3 grid (`grid-cols-3`) with all 8 buttons + 1 spacer in exact row-major order (`fullscreen`, `pan-up`, `zoom-in`, `pan-left`, `reset`, `pan-right`, spacer, `pan-down`, `zoom-out`), and verify every `data-mermaid-*` attribute appears exactly once in `.mermaid-block`.
    - `mermaidPanZoom.test.ts`:
      - Add tests for `MERMAID_PAN_STEP`, `stepMermaidPan` (positive/negative offsets, zoom preservation, non-finite inputs), `formatMermaidZoomPercent` (`1` -> `'100%'`, `1.2` -> `'120%'`, clamping `< 0.25` -> `'25%'`, `> 4.0` -> `'400%'`, `NaN` -> `'100%'`), and `panMermaidBlock` (DOM mock verifying `x`/`y` updates and `transform` application across sequential directional steps).
    - `mermaid.test.ts`:
      - Add tests for `getCleanMermaidSvg` (returns pristine cached SVG from `svgCache` without inline transforms; falls back to cloned DOM SVG with inline `transform`/`transformOrigin`/`transition` cleared; returns `''` when no SVG exists).
  - Covers: FR-01, FR-02, FR-03, FR-04, FR-05, FR-06

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/js/editor/markdownPreview.test.ts` | Non-redundant `.mermaid-toolbar` (4 actions only), `.mermaid-nav` 3×3 D-pad layout & order, single occurrence of each `data-mermaid-*` button, accessible labels/titles | FR-01, FR-02 |
| `tests/js/editor/mermaidPanZoom.test.ts` | `stepMermaidPan`, `panMermaidBlock` directional offsets, `formatMermaidZoomPercent` formatting/clamping, existing zoom/reset/SVG resolution checks | FR-03, FR-06 |
| `tests/js/editor/mermaid.test.ts` | `getCleanMermaidSvg` cache hit, fallback DOM SVG transform stripping, empty/missing SVG handling | FR-04, FR-05 |
| TypeScript & Linter checks | `npm run types:check` (`vue-tsc --noEmit`), `npm run check` (`vp check`: oxlint + oxfmt + Tailwind class sort), `npm run build` | FR-01..FR-06 |

**Test scope for QA**:
- Run `npm run test:js` (specifically covering `tests/js/editor/markdownPreview.test.ts`, `tests/js/editor/mermaidPanZoom.test.ts`, `tests/js/editor/mermaid.test.ts`, and remaining `tests/js/editor/*.test.ts`)
- Run `npm run types:check` (`vue-tsc --noEmit`)
- Run `npm run check` (`vp check`)
- Run `npm run build` (`vp build`) to verify production bundle compiles cleanly with Tailwind CSS v4 and Vite
- *(No PHP files are modified by this feature; `vendor/bin/phpstan analyse` and PHP feature tests are unaffected unless backend files are touched.)*

---

## 5. Risks & Mitigations
- **Risk**: `DialogContent.vue`'s `sm:max-w-lg` class constrains the fullscreen overlay width on desktop screens. — **Mitigation**: Pass both `max-w-none` and `sm:max-w-none` to `<DialogContent>` in `MermaidFullscreenDialog.vue` so `tailwind-merge` overrides both responsive and base max-width utilities.
- **Risk**: Top-right `.mermaid-toolbar` and bottom-right `.mermaid-nav` collide on very short diagrams. — **Mitigation**: Use compact `1.5rem` (`24px`) D-pad buttons and enforce `min-height: 9.75rem` on `.mermaid-block[data-mermaid-rendered='true']`.
- **Risk**: Fullscreen viewer inherits inline `scale`/`translate` transform from the preview block's SVG. — **Mitigation**: Extract clean SVG via `getCleanMermaidSvg` (preferring `svgCache` and stripping inline transform styles on fallback clone).
- **Risk**: `vp check` (`oxfmt` Tailwind sorter / `oxlint` `denyWarnings: true`) fails on unsorted Tailwind classes or unused variables. — **Mitigation**: Senior Developer must run `npm run check` and `npm run types:check` before handoff to QA.

---

## 6. Open Questions
None.

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-11-18 | Initial plan | — |
