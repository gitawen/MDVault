# Requirements: Mermaid Diagram Editor and View

## Metadata
- **Feature Name**: Mermaid Diagram Editor and View
- **Feature ID**: feat-mermaid-editor-view
- **Author**: System Analyst
- **Created Date**: 2026-11-15
- **Task Complexity**: Level 3 — Complex Development
- **Status**: APPROVED

---

## 1. Problem Statement
MDVault provides a privacy-first, offline-capable Markdown note vault built on CodeMirror 6 and Marked. While it currently supports GitHub Flavored Markdown (GFM) including tables, task lists, code blocks with syntax highlighting, and frontmatter stripping, users cannot create or visualize diagrams, flowcharts, architectures, or sequence diagrams directly within their notes.

Users who document technical architectures, system flows, state machines, and relational schemas must either use external tools (like Draw.io or Excalidraw) and export raster images into their vaults, or write raw Mermaid text blocks that only render as unstyled plain code.

Adding native Mermaid diagram editing and rendering will empower MDVault users to author, preview, and maintain rich interactive diagrams directly inside their Markdown notes without sacrificing MDVault's security, performance, or theme cohesion.

---

## 2. Goals & Non-Goals

### In Scope (Goals)
1. **Live Diagram Rendering in Preview & Split Modes**: Automatically render ````mermaid```` fenced code blocks as crisp, scalable SVG diagrams in both `preview` and `split` display modes.
2. **Dedicated Mermaid Diagram Editor Dialog (`MermaidDialog.vue`)**: Provide a modal dialog accessible from the editor toolbar and existing diagrams, offering:
   - Preset diagram templates (Flowchart, Sequence, Class, State, Entity-Relationship, Gantt, Pie, Mindmap, Git Graph).
   - Live side-by-side editing with instant SVG rendering.
   - Immediate syntax validation with clear line/error feedback.
   - One-click insertion or in-place replacement into the active CodeMirror document.
3. **Editor Toolbar & Shortcut Integration**:
   - Add a "Diagram" command (`Workflow` icon) to `EditorToolbar.vue` in the primary block tools and mobile overflow menu.
   - Register a CodeMirror keyboard shortcut (`Ctrl+Alt+D` / `Cmd+Alt+D`).
4. **Theme Harmonization (Light & Dark)**:
   - Match MDVault's light and dark themes seamlessly.
   - Automatically re-render all diagrams when the application theme toggles between light, dark, or system modes.
5. **Diagram Quick Actions**:
   - Provide "Copy diagram source", "Copy SVG", and "Edit diagram" action buttons on rendered diagram blocks in the preview pane.
6. **Strict Security & XSS Mitigation**:
   - Initialize Mermaid with `securityLevel: 'strict'`.
   - Ensure diagram labels and attributes cannot execute arbitrary scripts or inject malicious HTML into the host DOM.
7. **Performance & Lazy Loading**:
   - Dynamically load the Mermaid library on-demand only when a note contains Mermaid blocks or when opening the Mermaid editor dialog.
   - Cache rendered SVGs by diagram content and theme to prevent re-compilation lag while typing in Split view.
8. **Node.js Test Environment Compatibility**:
   - Preserve full compatibility with Vitest (`environment: 'node'`) so `markdownPreview.ts` and core editor utilities continue running headless without browser DOM dependencies.

### Out of Scope (Non-Goals)
- Visual drag-and-drop node editing (Mermaid is a text-based diagram-as-code DSL; visual node repositioning is outside its model).
- Direct export to external cloud diagram services (MDVault is strictly local-first and vault-isolated).
- Dynamic server-side diagram rasterization or PHP-based diagram rendering (diagrams are rendered entirely on the client side).

---

## 3. User Personas & Stories
- **Persona**: Software Engineer / Systems Architect
  - **Story**: As a software engineer documenting a microservice architecture in MDVault, I want to write ````mermaid```` code blocks and immediately see rendered flowcharts and sequence diagrams in Split mode, so that I can document system interactions alongside my notes.
- **Persona**: Student / Researcher
  - **Story**: As a student taking lecture notes, I want to insert common diagram templates (like Mindmaps, State machines, or Flowcharts) from the toolbar without having to memorize Mermaid syntax, so that I can quickly illustrate concepts.
- **Persona**: Technical Writer / Documentation Maintainer
  - **Story**: As a documentation writer reviewing notes in Preview mode, I want to click "Edit diagram" directly on a rendered diagram or copy its source/SVG, so that I can update diagram flows without hunting through hundreds of lines of Markdown source.

---

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | **Mermaid Code Block Parsing in Preview Pipeline** | `renderMarkdownPreview()` in `markdownPreview.ts` must identify fenced code blocks where `lang === 'mermaid'` and output a dedicated `.mermaid-block` container holding the escaped source in a `data-mermaid-source` attribute and fallback `<pre><code class="language-mermaid">` markup. | Given a note containing ````mermaid\ngraph TD\nA-->B\n````, When rendered via `renderMarkdownPreview()`, Then the output HTML contains a `<div class="mermaid-block" data-mermaid-source="...">` wrapper and does not invoke browser-only APIs. |
| **FR-02** | **Dynamic On-Demand Mermaid Loading** | The heavy Mermaid bundle must not be loaded eagerly on initial app boot. It must be dynamically imported (`await import('mermaid')`) only when a note has `.mermaid-block` elements in Split/Preview modes or when opening the Mermaid editor dialog. | Given a user opens a note without diagrams, When checking network/bundle resources, Then `mermaid` is not loaded. When the user switches to a note containing a diagram, Then `mermaid` is loaded and the diagram renders. |
| **FR-03** | **Interactive Diagram View with Actions** | Each rendered diagram in the preview pane must display action buttons: "Copy diagram source", "Copy SVG", and "Edit diagram". | Given a rendered diagram in the preview pane, When clicking "Copy diagram source", Then the raw Mermaid code is copied with visual feedback. When clicking "Copy SVG", Then the sanitized SVG markup is copied. When clicking "Edit diagram", Then the Mermaid Dialog opens with this diagram preloaded. |
| **FR-04** | **Dedicated Mermaid Editor Dialog (`MermaidDialog.vue`)** | Provide a dialog modal containing a template picker, code editor with syntax indentation, live real-time preview, error banner, and Insert/Update action. | Given the user opens the Mermaid Dialog, When typing valid Mermaid code, Then the preview updates in real-time. When typing invalid syntax, Then an error banner highlights the syntax issue without breaking the dialog. When clicking "Insert" (or "Update"), Then the diagram markdown is inserted/replaced in CodeMirror. |
| **FR-05** | **Preset Diagram Templates** | The Mermaid Dialog must provide pre-built templates for Flowchart, Sequence, Class, State, ER, Gantt, Pie, Mindmap, and Git Graph diagrams. | Given the Mermaid Dialog is open, When selecting "Sequence Diagram" from the template picker, Then the editor populates with a working sequence diagram template and renders it immediately. |
| **FR-06** | **Toolbar Command & Keymap Integration** | In `EditorToolbar.vue`, add a diagram button to the formatting tools and mobile overflow menu. In `codemirrorKeymap.ts`, bind `Ctrl+Alt+D` (`Cmd+Alt+D`). | Given the editor has focus, When pressing `Ctrl+Alt+D` or clicking the toolbar diagram button, Then the Mermaid Dialog opens. If the cursor is currently inside a ````mermaid```` block, the dialog preloads that block's code. |
| **FR-07** | **Theme Harmonization & Dynamic Re-rendering** | Diagrams must render in Mermaid's `'dark'` theme when MDVault is in dark mode, and `'default'` when in light mode. When the user or system switches themes, all visible diagrams must re-render to match. | Given a note with rendered diagrams in light mode, When switching the theme to dark mode, Then all diagrams immediately re-render using the dark theme palette. |
| **FR-08** | **Graceful Syntax Error Handling in Preview** | If a note contains invalid Mermaid syntax, the preview must render a clean, styled error box displaying the error message and an expandable "Show source" toggle, without throwing uncaught exceptions or blanking the preview pane. | Given a note with malformed Mermaid syntax (e.g. `graph TD\n -> broken`), When viewing in Preview or Split mode, Then a styled error alert is displayed showing the syntax issue and the rest of the note preview renders normally. |
| **FR-09** | **Strict Security & XSS Prevention** | Mermaid must run with `securityLevel: 'strict'`. Diagram source must be HTML-escaped before insertion into data attributes or fallback code tags. Malicious tags (`<script>`, `onerror`, `javascript:`) in diagram source must not execute. | Given a diagram with labels containing `<script>alert(1)</script>` or `click node call alert()`, When rendered, Then no script executes and no unescaped tags enter the host DOM. |
| **FR-10** | **SVG Caching & Split-View Debounce** | Rendered diagram SVGs must be cached in memory by `(source, theme)` so that typing elsewhere in a document during Split view does not trigger redundant Mermaid re-compilations. | Given a note with diagrams open in Split view, When the user types text in a paragraph outside the diagram, Then the cached diagram SVGs are reused without recompiling. |
| **FR-11** | **CodeMirror Commands & Block Detection** | `codemirrorCommands.ts` must provide `findMermaidBlockAt(state, pos)` to detect if the caret is inside a mermaid block and `insertMermaidBlock(view, code, range)` to insert or update. | Given the caret is inside a ````mermaid```` code block, When invoking `findMermaidBlockAt()`, Then it returns the exact start/end range and inner content of the block. |
| **FR-12** | **Headless Vitest Test Suite Compatibility** | All exports from `markdownPreview.ts` and core commands must function safely in a Node.js test environment (`npm run test:js`) without requiring a real browser `document` or `window`. | Given the test suite runs with `npm run test:js`, When running markdown preview and command tests, Then all tests pass in the `node` environment without DOM errors. |

---

## 5. Non-Functional Requirements
- **Security & Authorization**:
  - `securityLevel: 'strict'` prevents execution of embedded scripts, HTML clicks, and iframe hijacking.
  - Safe HTML escaping for all code and attributes before inserting into `v-html`.
- **Performance**:
  - Mermaid bundle lazy-loaded via dynamic import (`~1.5 MB` deferred).
  - SVGs cached in an in-memory `Map` keyed by `theme:hash`.
  - Debounced rendering (aligned with the editor's existing 200ms preview debounce).
- **Accessibility & UX**:
  - Action buttons (`data-mermaid-copy`, `data-mermaid-edit`) include accessible `aria-label` and `title` attributes.
  - Smooth visual transitions between loading skeleton and rendered SVG.
  - Mermaid Dialog supports keyboard navigation (`Esc` to close, `Ctrl+Enter` to insert/apply).
- **Reliability & Data Integrity**:
  - Editing diagrams replaces only the targeted fenced code block in Markdown; frontmatter and surrounding text remain intact.
  - Malformed diagram syntax never halts the Markdown parser or corrupts note contents.

---

## 6. Technical Constraints & Context
- Framework: Vue 3 (Composition API `<script setup lang="ts">`), Inertia.js v3
- Editor: CodeMirror 6 with `@codemirror/lang-markdown`
- Markdown Parser: `marked` (v17.x)
- Icons: `@lucide/vue`
- Styling: Tailwind CSS v4 with CSS variables (`var(--background)`, `var(--foreground)`, `var(--border)`, `var(--primary)`)
- Testing: Vitest (`vite-plus/test`) in `node` environment (`npm run test:js`), TypeScript (`npm run types:check`)
- Dependency to add: `mermaid` (latest v11.x) — **Requires user approval**

---

## 7. Risks & Assumptions
- **Assumption 1**: The user approves installing `mermaid` as an npm dependency.
- **Assumption 2**: Client-side rendering is preferred because MDVault is a local-first desktop/web vault with encrypted storage; note content should never be transmitted to third-party rendering APIs.
- **Risk 1 & Mitigation (Bundle Size)**: `mermaid` is a large package (~1.5 MB).
  *Mitigation*: Dynamic `import('mermaid')` inside `loadMermaid()` ensures the bundle is only fetched when a diagram is actively present or the Mermaid Dialog is opened.
- **Risk 2 & Mitigation (Node Test Environment)**: Mermaid requires browser DOM APIs (`DOMParser`, `document`, `SVGElement`) which do not exist in Vitest's `node` environment.
  *Mitigation*: The Markdown preview parsing in `markdownPreview.ts` only generates HTML markup with data attributes; actual SVG compilation occurs in a browser-only renderer (`mermaidRenderer.ts`) with `typeof window !== 'undefined'` guards.

---

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified
- [x] Approved to proceed to Planning (`plan.md`)