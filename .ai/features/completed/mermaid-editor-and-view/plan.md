# Plan: Mermaid Diagram Editor and View

## Metadata
- **Feature Name**: Mermaid Diagram Editor and View
- **Feature ID**: feat-mermaid-editor-view
- **Author**: System Analyst
- **Created Date**: 2026-11-15
- **Task Complexity**: Level 3 — Complex Development
- **Requirements**: `requirements.md`
- **Status**: APPROVED

---

## 1. Summary
This plan adds native Mermaid diagram editing, live rendering, and interactive diagram controls to MDVault. The implementation uses a two-phase architecture: `renderMarkdownPreview()` in `markdownPreview.ts` parses ````mermaid```` code blocks synchronously and emits a clean, secure `.mermaid-block` container; in the browser, a lazy-loaded `mermaidRenderer.ts` dynamically imports `mermaid` on demand, compiles SVGs with `securityLevel: 'strict'`, caches rendered output in memory, and re-renders automatically when switching light and dark themes. A dedicated modal editor (`MermaidDialog.vue`) provides pre-built diagram templates (Flowchart, Sequence, State, ER, Gantt, Pie, Mindmap, Git Graph), live real-time preview, and instant syntax validation, accessible via toolbar, keyboard shortcut (`Ctrl+Alt+D`), or direct "Edit diagram" buttons on rendered diagrams.

---

## 2. Architecture & Design
- **Approach**: Two-phase rendering with dynamic lazy loading.
  - *Phase 1 (Sync Parsing)*: Marked parser in `markdownPreview.ts` outputs semantic `.mermaid-block` HTML without importing browser-only libraries, keeping all tests 100% compatible with Vitest's `node` test environment.
  - *Phase 2 (Async Client-Side Rendering)*: `mermaidRenderer.ts` dynamically imports `mermaid` only when diagram blocks exist, configures `securityLevel: 'strict'` to prevent XSS, caches compiled SVGs by `(source, theme)` to keep Split-view typing smooth, and renders SVGs into the DOM.
  - *Editor Experience*: A dedicated `MermaidDialog.vue` dialog (patterned after `LinkDialog.vue`) lets users pick presets, edit diagram code, view real-time compilation with instant error feedback, and insert or update diagrams at the CodeMirror cursor position.
- **Alternatives Considered**:
  - *Eager import in `markdownPreview.ts`*: Rejected because `mermaid` adds ~1.5 MB unminified to the initial bundle for all users and crashes headless Node.js tests.
  - *Server-side rendering via PHP/Node*: Rejected because MDVault is a local-first, privacy-focused vault supporting encrypted notes; diagram content must not leave the client.
- **Decision Records**: `.ai/decisions/mermaid-client-rendering-and-security.md`

### Data Model Changes
None. Diagram source code is stored directly in standard Markdown fenced code blocks (` ```mermaid ... ``` `) within note files.

### Backend Components
None. This is an entirely client-side feature.

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Dialog Component | `resources/js/components/editor/MermaidDialog.vue` | Modal dialog for authoring/editing Mermaid diagrams with preset templates, live preview, syntax error feedback, and apply action. |
| Utility Module | `resources/js/lib/editor/mermaidTemplates.ts` | Preset diagram templates (Flowchart, Sequence, Class, State, ER, Gantt, Pie, Mindmap, Git Graph) with starter code and descriptions. |
| Utility Module | `resources/js/lib/editor/mermaidRenderer.ts` | Dynamic lazy loading of `mermaid`, theme configuration (light/dark), SVG compilation with `securityLevel: 'strict'`, in-memory SVG caching, error formatting, and container DOM rendering. |
| Parser Module | `resources/js/lib/editor/markdownPreview.ts` | Marked custom code renderer extension to recognize `lang === 'mermaid'` and emit `.mermaid-block` markup with escaped attributes. |
| Editor Component | `resources/js/components/editor/MarkdownEditor.vue` | Mounts `renderMermaidInContainer`, watches `[renderedHtml, isDark, displayMode]`, handles preview click actions (`data-mermaid-copy`, `data-mermaid-copy-svg`, `data-mermaid-edit`, `data-mermaid-toggle-source`). |
| Toolbar Component | `resources/js/components/editor/EditorToolbar.vue` | Adds Diagram button to the block tools and overflow menu; hosts `MermaidDialog` instance and exposes `openMermaidDialog()`. |
| CodeMirror Commands | `resources/js/lib/editor/codemirrorCommands.ts` | Adds `findMermaidBlockAt()`, `insertMermaidBlock()`, and `isMermaidBlockActive()`. |
| Toolbar Commands | `resources/js/lib/editor/toolbarCommands.ts` | Registers `mermaid` command with `Ctrl+Alt+D` shortcut and active/run definitions. |
| Keymap | `resources/js/lib/editor/codemirrorKeymap.ts` | Binds `Mod-Alt-d` to trigger `onMermaid`. |
| CodeMirror Setup | `resources/js/lib/editor/codemirror.ts` | Passes `onMermaid` callback into CodeMirror extensions. |

---

## 3. Implementation Tasks
*Ordered. Each task leaves the application in a working state.*

- [ ] **T1 — Install `mermaid` NPM Dependency**
  - **Requires user approval**
  - Files: `package.json`
  - Command: `npm install mermaid`
  - Details: Add `mermaid` (v11.x) to `dependencies` in `package.json`. Confirm `package.json` and `package-lock.json` are cleanly updated.
  - Covers: [FR-02]

- [ ] **T2 — Create Mermaid Templates Library**
  - Files: `resources/js/lib/editor/mermaidTemplates.ts`
  - Details:
    - Create `MermaidTemplate` type with `{ id: string; name: string; description: string; code: string }`.
    - Provide templates for:
      - `flowchart`: `graph TD\n    Start([Start]) --> Process[Process Task]\n    Process --> Decision{Is valid?}\n    Decision -- Yes --> Success([Complete])\n    Decision -- No --> Error([Handle Error])`
      - `sequence`: `sequenceDiagram\n    autonumber\n    actor User\n    participant Client\n    participant Server\n    User->>Client: Open note\n    Client->>Server: Request note data\n    Server-->>Client: Return note content\n    Client-->>User: Display rendered note`
      - `state`: `stateDiagram-v2\n    [*] --> Draft\n    Draft --> Review: Submit\n    Review --> Approved: Accept\n    Review --> Rejected: Request Changes\n    Rejected --> Draft: Edit\n    Approved --> [*]`
      - `class`: `classDiagram\n    class Vault {\n        +String uuid\n        +String name\n        +Boolean isEncrypted\n        +lock()\n        +unlock(key)\n    }\n    class Note {\n        +String path\n        +String content\n        +save()\n    }\n    Vault "1" *-- "*" Note : contains`
      - `er`: `erDiagram\n    VAULT ||--o{ NOTE : contains\n    NOTE ||--o{ TAG : labeled_with\n    NOTE {\n        string uuid PK\n        string title\n        string relative_path\n    }`
      - `gantt`: `gantt\n    title Vault Synchronization Roadmap\n    dateFormat YYYY-MM-DD\n    section Core\n    Index Engine :done, 2026-01-01, 2026-01-15\n    Mermaid Integration :active, 2026-01-16, 2026-01-30\n    section UI\n    Toolbar Updates :2026-02-01, 2026-02-10`
      - `pie`: `pie title Note Types in Vault\n    "Markdown Notes" : 65\n    "Diagrams & Specs" : 20\n    "Code Snippets" : 15`
      - `mindmap`: `mindmap\n  root((MDVault))\n    Architecture\n      CodeMirror 6\n      Marked Preview\n      Mermaid Diagrams\n    Storage\n      Local Filesystem\n      AES-GCM Encryption\n    Platform\n      Desktop Electron\n      Web SPA`
      - `gitGraph`: `gitGraph\n    commit id: "Initial commit"\n    branch feature/mermaid\n    checkout feature/mermaid\n    commit id: "Add parser"\n    commit id: "Add editor dialog"\n    checkout main\n    merge feature/mermaid id: "Merge diagram feature"`
    - Export `MERMAID_TEMPLATES`, `getMermaidTemplate(id: string)`, and `DEFAULT_MERMAID_CODE`.
  - Covers: [FR-05]

- [ ] **T3 — Create Client Mermaid Renderer & SVG Cache Engine**
  - Files: `resources/js/lib/editor/mermaidRenderer.ts`
  - Details:
    - Implement `loadMermaid()`: dynamic `await import('mermaid')`, singleton initialization check with `securityLevel: 'strict'`.
    - Implement `getMermaidTheme(isDark: boolean): 'dark' | 'default'`.
    - Implement `getMermaidConfig(isDark: boolean)`: sets `securityLevel: 'strict'`, `startOnLoad: false`, `theme: getMermaidTheme(isDark)`, and `fontFamily: 'inherit'`.
    - Implement `svgCache: Map<string, string>` (keyed by `${theme}:${source.trim()}`) with `clearSvgCache()`.
    - Implement `renderMermaidDiagram(source: string, isDark: boolean, id?: string): Promise<{ svg: string }>`:
      - Validates and trims source.
      - Checks `svgCache` first for instant hit.
      - Generates unique ID (`mermaid-svg-${Date.now()}-${counter++}`).
      - Invokes `mermaid.render(id, source)` inside try/catch.
      - Caches resulting SVG and returns it.
      - In catch block, throws formatted error with message and line information.
    - Implement `renderMermaidInContainer(container: HTMLElement, isDark: boolean, onEdit?: (source: string, index: number) => void): Promise<void>`:
      - Finds all `.mermaid-block` elements in `container`.
      - If none found, exits immediately without loading `mermaid`.
      - If found, loads `mermaid`, iterates each block:
        - Checks `element.dataset.renderedTheme === (isDark ? 'dark' : 'light')` to skip unchanged diagrams.
        - Gets `source` from `element.dataset.mermaidSource`.
        - Calls `renderMermaidDiagram(source, isDark)`.
        - Injects SVG into `.mermaid-container` child.
        - Removes `.mermaid-loading` skeleton.
        - Sets `element.dataset.renderedTheme = isDark ? 'dark' : 'light'`.
        - On compilation error: renders styled error alert with warning icon, error description, and a button (`data-mermaid-toggle-source`) to toggle the raw source `<pre>` code block.
    - Implement clipboard helper `copyMermaidSvg(svg: string): Promise<boolean>`.
  - Covers: [FR-02, FR-03, FR-07, FR-08, FR-09, FR-10]

- [ ] **T4 — Update Marked Preview Pipeline for Mermaid Code Blocks**
  - Files: `resources/js/lib/editor/markdownPreview.ts`
  - Details:
    - In `markdownParser.use({ renderer: { code({ text, lang, escaped }) { ... } } })`:
    - Check if `langClass?.toLowerCase() === 'mermaid'`.
    - When `mermaid` is matched, construct HTML:
      ```html
      <div class="mermaid-block relative group my-4 rounded-lg border border-border/70 bg-card/60 p-4 transition-all" data-mermaid-source="${escapeAttribute(rawSource)}">
          <div class="mermaid-toolbar absolute top-2.5 right-2.5 flex items-center gap-1 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity z-10">
              <button type="button" class="mermaid-action-btn" aria-label="Copy diagram source" title="Copy diagram source" data-mermaid-copy>
                  ${COPY_ICON_SVG}
              </button>
              <button type="button" class="mermaid-action-btn" aria-label="Copy SVG" title="Copy SVG" data-mermaid-copy-svg>
                  <svg ...><!-- SVG icon --></svg>
              </button>
              <button type="button" class="mermaid-action-btn" aria-label="Edit diagram" title="Edit diagram" data-mermaid-edit>
                  <svg ...><!-- Edit icon --></svg>
              </button>
          </div>
          <div class="mermaid-container flex justify-center items-center min-h-[60px] overflow-x-auto py-2">
              <div class="mermaid-loading flex items-center gap-2 text-xs text-muted-foreground">
                  <span class="animate-pulse">Rendering diagram...</span>
              </div>
          </div>
          <pre class="mermaid-raw hidden"><code>${escapeHtml(codeContent)}</code></pre>
      </div>\n
      ```
    - Ensure zero browser-only globals (`window`, `document`) are referenced in `markdownPreview.ts` so Vitest `node` tests continue running cleanly.
  - Covers: [FR-01, FR-09, FR-12]

- [ ] **T5 — Add CodeMirror Commands & Range Detection for Mermaid Blocks**
  - Files: `resources/js/lib/editor/codemirrorCommands.ts`
  - Details:
    - Implement `findMermaidBlockAt(state: EditorState, pos: number): { from: number; to: number; code: string } | null`:
      - Uses `syntaxTree(state)` or bounded line scan around `pos` to find an enclosing `FencedCode` node.
      - Checks if the fence info string starts with `mermaid`.
      - Extracts inner code content (excluding ``` delimiters).
      - Returns `{ from, to, code }` or `null`.
    - Implement `insertMermaidBlock(view: CommandTarget, code: string, range?: { from: number; to: number }): boolean`:
      - If `range` is provided, replaces that range with ````mermaid\n${code.trim()}\n````\n.
      - If no range, inserts at current cursor position (normalizing newlines before and after).
      - Dispatches transaction with `userEvent: 'format.mermaid'`.
    - Implement `isMermaidBlockActive(state: EditorState): boolean`:
      - Returns `true` if `findMermaidBlockAt(state, state.selection.main.head) !== null`.
  - Covers: [FR-11]

- [ ] **T6 — Update Toolbar Commands, Keymap, and CodeMirror Extensions**
  - Files:
    - `resources/js/lib/editor/toolbarCommands.ts`
    - `resources/js/lib/editor/codemirrorKeymap.ts`
    - `resources/js/lib/editor/codemirror.ts`
  - Details:
    - In `toolbarCommands.ts`:
      - Add `'mermaid'` to `ToolbarCommandId`.
      - Add `mermaid` entry in `toolbarCommands`:
        ```ts
        mermaid: {
            label: 'Diagram (Mermaid)',
            shortcut: 'Ctrl+Alt+D',
            run: () => true, // Opens the dialog in Vue component
            isActive: (editor) => isMermaidBlockActive(editor.state),
            canRun: () => true,
        }
        ```
    - In `codemirrorKeymap.ts`:
      - Update `markdownFormattingKeymap(options: { onLink: () => void; onMermaid?: () => void })`.
      - Bind `{ key: 'Mod-Alt-d', run: () => { options.onMermaid?.(); return true; } }`.
    - In `codemirror.ts`:
      - Pass `onMermaid` through `buildCodeMirrorExtensions` options to `markdownFormattingKeymap`.
  - Covers: [FR-06]

- [ ] **T7 — Create the Mermaid Editor Dialog Component (`MermaidDialog.vue`)**
  - Files: `resources/js/components/editor/MermaidDialog.vue`
  - Details:
    - Props: `view?: EditorView | undefined`, `initialCode?: string`, `range?: { from: number; to: number }`.
    - Model: `open = defineModel<boolean>('open', { default: false })`.
    - Emits: `apply: [code: string]`.
    - Structure:
      - Uses MDVault's `Dialog`, `DialogContent`, `DialogHeader`, `DialogTitle`, `DialogDescription`, `DialogFooter`, `Button`, `Label`.
      - Header: "Mermaid Diagram Editor" with description "Create or edit diagrams using Mermaid syntax."
      - Template picker: Select dropdown or quick-action buttons for preset templates (Flowchart, Sequence, State, Class, ER, Gantt, Pie, Mindmap, Git Graph).
      - Body: Two-column layout (editor on left, preview on right; responsive stack on small screens):
        - Left: Code editor textarea with monospace font, line wrapping, tab-key indentation support (`indentWithTab` simulation), and character count.
        - Right: Live diagram preview card with background matching current theme. Calls `renderMermaidDiagram(code, isDark)` debounced by 200ms.
        - Error display: When compilation throws, displays an inline Alert with destructive styling showing the syntax error message without breaking the dialog.
      - Footer: "Insert Diagram" (or "Update Diagram" if `props.range` is set), "Copy SVG", and "Cancel" buttons.
      - Keyboard shortcut: `Ctrl+Enter` / `Cmd+Enter` to apply; `Esc` to cancel.
  - Covers: [FR-04, FR-05, FR-07]

- [ ] **T8 — Integrate Mermaid Dialog into `EditorToolbar.vue`**
  - Files: `resources/js/components/editor/EditorToolbar.vue`
  - Details:
    - Import `Workflow` (or `GitFork`) icon from `@lucide/vue`.
    - In `overflowCommandIcons`, map `mermaid: Workflow`.
    - In `overflowCommands`, append `'mermaid'`.
    - In the desktop toolbar Links & Blocks group:
      - Add a Diagram button next to `table` / `codeBlock`:
        ```html
        <Button
            variant="ghost"
            size="icon-sm"
            class="hidden size-7 rounded-sm transition-all sm:size-7.5 md:inline-flex"
            :class="isActive('mermaid') ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
            :aria-pressed="isActive('mermaid')"
            :disabled="!props.view"
            :title="title('mermaid')"
            @click="openMermaidDialog()"
        >
            <Workflow class="size-3.5 sm:size-4" />
        </Button>
        ```
    - Add state: `mermaidDialogOpen = ref(false)`, `mermaidInitialCode = ref('')`, `mermaidRange = ref<{ from: number; to: number } | undefined>(undefined)`.
    - Method `openMermaidDialog(initialCode?: string, range?: { from: number; to: number })`:
      - If arguments provided, sets them.
      - Otherwise, inspects `props.view` with `findMermaidBlockAt(state, pos)`. If inside a mermaid block, preloads it.
      - Opens dialog.
    - Expose `openMermaidDialog` via `defineExpose({ openLinkDialog, openMermaidDialog })`.
    - Mount `<MermaidDialog v-model:open="mermaidDialogOpen" :view="props.view" :initial-code="mermaidInitialCode" :range="mermaidRange" />`.
  - Covers: [FR-06]

- [ ] **T9 — Connect Diagram Rendering, Theme Watching & Preview Actions in `MarkdownEditor.vue`**
  - Files: `resources/js/components/editor/MarkdownEditor.vue`
  - Details:
    - Wire `onMermaid: () => toolbarRef.value?.openMermaidDialog()` into `buildCodeMirrorExtensions`.
    - Add debounced watch on `[renderedHtml, isDark, displayMode]`:
      - When `displayMode !== 'code'`, wait for `nextTick()`.
      - Query preview container element.
      - Call `renderMermaidInContainer(previewEl, isDark.value)`.
    - In `handlePreviewClick(event: MouseEvent)`:
      - Handle `button[data-mermaid-copy]`:
        - Finds closest `.mermaid-block`.
        - Extracts `data-mermaid-source` or `.mermaid-raw code` text.
        - Invokes `triggerCopyFeedback(btn, source)`.
      - Handle `button[data-mermaid-copy-svg]`:
        - Finds closest `.mermaid-block`.
        - Extracts `.mermaid-container svg` outerHTML.
        - Copies SVG string to clipboard and triggers copy feedback.
      - Handle `button[data-mermaid-edit]`:
        - Finds closest `.mermaid-block`.
        - Extracts source code.
        - Calls `toolbarRef.value?.openMermaidDialog(source)`.
      - Handle `button[data-mermaid-toggle-source]`:
        - Toggles `.mermaid-error-source.hidden` class to show/hide raw code.
    - Add CSS styles in `<style>`:
      - `.mermaid-block`: background, border, relative positioning, border-radius.
      - `.mermaid-action-btn`: compact icon button styling matching `.code-copy-btn`.
      - `.mermaid-container svg`: max-width 100%, height auto, display block, center alignment.
      - `.mermaid-error`: destructive banner styling with monospace error details.
  - Covers: [FR-01, FR-02, FR-03, FR-07, FR-08, FR-09, FR-10]

- [ ] **T10 — Unit & Integration Test Suite**
  - Files:
    - `tests/js/editor/markdownPreview.test.ts`
    - `tests/js/editor/codemirrorCommands.test.ts`
    - `tests/js/editor/mermaid.test.ts` (new)
  - Details:
    - In `markdownPreview.test.ts`:
      - Add test: `renders fenced mermaid code blocks in .mermaid-block wrapper with data-mermaid-source`.
      - Add test: `escapes special characters and hostile scripts inside mermaid block source`.
      - Add test: `includes copy, copy-svg, and edit action buttons on the mermaid wrapper`.
      - Add test: `handles empty mermaid block gracefully`.
    - In `codemirrorCommands.test.ts`:
      - Add test: `findMermaidBlockAt detects mermaid block and extracts range and code when caret is inside`.
      - Add test: `findMermaidBlockAt returns null when caret is on plain line or non-mermaid code block`.
      - Add test: `insertMermaidBlock inserts formatted mermaid block with fences at cursor`.
      - Add test: `insertMermaidBlock replaces existing range when editing an existing diagram`.
      - Add test: `isMermaidBlockActive returns true when caret is inside a mermaid block`.
    - In `mermaid.test.ts`:
      - Add test: `provides valid presets for all diagram types (flowchart, sequence, state, class, er, gantt, pie, mindmap, gitGraph)`.
      - Add test: `getMermaidTheme maps false to 'default' and true to 'dark'`.
      - Add test: `getMermaidConfig specifies securityLevel strict`.
      - Add test: `svgCache generates stable cache keys and caches diagram results`.
    - Verification:
      - Run `npm run test:js`.
      - Run `npm run types:check`.
  - Covers: [FR-01, FR-05, FR-07, FR-09, FR-11, FR-12]

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/js/editor/markdownPreview.test.ts` | Fenced `mermaid` code block produces `.mermaid-block`, `data-mermaid-source`, and action buttons | [FR-01, FR-03] |
| `tests/js/editor/markdownPreview.test.ts` | Hostile tags (`<script>`) in mermaid block are escaped and do not render live | [FR-09, FR-12] |
| `tests/js/editor/codemirrorCommands.test.ts` | `findMermaidBlockAt` extracts range and code when cursor is inside mermaid block | [FR-11] |
| `tests/js/editor/codemirrorCommands.test.ts` | `insertMermaidBlock` inserts or replaces diagram block cleanly | [FR-11] |
| `tests/js/editor/mermaid.test.ts` | Template generator yields all 9 diagram types with valid syntax | [FR-05] |
| `tests/js/editor/mermaid.test.ts` | `getMermaidTheme` correctly maps light/dark mode | [FR-07] |
| `tests/js/editor/mermaid.test.ts` | `getMermaidConfig` enforces `securityLevel: 'strict'` | [FR-09] |
| `tests/js/editor/mermaid.test.ts` | SVG caching key generation and cache hit/miss behavior | [FR-10] |

**Test scope for QA**:
- `tests/js/editor/markdownPreview.test.ts`
- `tests/js/editor/codemirrorCommands.test.ts`
- `tests/js/editor/mermaid.test.ts`
- Run `npm run test:js` (all JavaScript unit tests must pass)
- Run `npm run types:check` (zero TypeScript errors)
- Manual browser verification:
  - Create note with a flowchart diagram. Verify it compiles cleanly in Split and Preview views.
  - Test theme switching: toggle dark mode on/off; verify diagram re-renders with matching dark/light palette.
  - Test syntax error resilience: introduce a syntax error; verify styled error alert displays without crashing the preview.
  - Test dialog workflow: click "Diagram" toolbar button, choose a preset, edit code, click "Insert Diagram". Verify it writes to CodeMirror.
  - Test "Edit diagram" in preview: click "Edit diagram" on a rendered block; verify dialog opens with that diagram's source and updates in place upon save.

---

## 5. Risks & Mitigations
- **Risk**: Adding `mermaid` increases bundle size.
  - **Mitigation**: Lazy load `mermaid` via dynamic `import('mermaid')` only when diagrams exist or the dialog opens. Notes without diagrams incur zero overhead.
- **Risk**: Mermaid fails in Node.js test environment (`vite-plus/test`).
  - **Mitigation**: `markdownPreview.ts` outputs semantic HTML without calling Mermaid APIs. All browser-specific rendering is quarantined in `mermaidRenderer.ts` with environment guards.
- **Risk**: Rapid typing in Split view causes high CPU usage from re-compiling diagrams.
  - **Mitigation**: Diagrams are rendered with 200ms debounce and SVGs are cached in memory by `${theme}:${source}` so unchanged diagrams re-use existing SVGs instantly.
- **Risk**: Untrusted notes contain malicious Mermaid scripts or XSS payloads.
  - **Mitigation**: Mermaid initialized strictly with `securityLevel: 'strict'`. All raw content is HTML-escaped before insertion into DOM attributes or fallback blocks.

---

## 6. Open Questions
- None. Requirements, security constraints, and architecture are fully resolved.

---

## 7. Dependencies Requiring User Approval
- **Package**: `mermaid`
- **Installation Command**: `npm install mermaid`
- **Purpose**: Client-side diagram compilation and SVG rendering for flowcharts, sequence diagrams, state machines, and architectures.

---

## 8. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-11-15 | Initial plan | Complete 10-task implementation plan, requirements mapping, and test scope. |