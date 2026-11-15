# Architectural Decision Record: Client-Side Mermaid Rendering & Security Architecture

## Context
MDVault is a privacy-first, local-first Markdown vault application. Notes are stored locally on the user's filesystem and can be encrypted with AES-256-GCM. MDVault's Markdown rendering pipeline uses CodeMirror 6 for rich text editing and Marked for live preview (supporting Editor, Split, and Preview display modes).

We are introducing native Mermaid diagram editing and rendering. Mermaid diagrams are represented as fenced code blocks with the `mermaid` language identifier (` ```mermaid ... ``` `).

Key architectural challenges include:
1. **Bundle Size & Loading Overhead**: The `mermaid` package is ~1.5 MB unminified. Eagerly importing it into the core app bundle would inflate the initial load time for all notes, even when no diagrams are present.
2. **Synchronous Marked vs Asynchronous Mermaid**: `renderMarkdownPreview()` in `markdownPreview.ts` is synchronous (`markdownParser.parse(..., { async: false })`), while `mermaid.render()` is asynchronous and returns a `Promise<{ svg: string }>`.
3. **Vitest Node Environment**: The test environment in `vite.config.ts` is configured as `environment: 'node'`. Mermaid requires browser DOM APIs (`document.createElementNS`, `getBBox()`, etc.) and crashes in Node if imported eagerly or executed without a DOM.
4. **Security & Untrusted Notes**: MDVault users can open imported notes from untrusted sources. Diagrams could contain malicious script tags, click callbacks, or image payloads attempting XSS.
5. **Theme Switching**: MDVault supports dynamic light and dark theme switching without full page reloads. Diagram styling must harmonize with the active theme and update seamlessly.

---

## Options Considered

### Option 1: Eager Import in `markdownPreview.ts` with JSDOM
- Parse and render Mermaid SVGs synchronously or within an async Marked pipeline.
- *Why rejected*: Severely bloats the initial bundle by ~1.5 MB for all users; requires changing `renderMarkdownPreview` to asynchronous throughout the app and tests; requires bringing JSDOM into the production bundle to simulate browser DOM.

### Option 2: Server-Side PHP/Node Rendering API
- Send the Mermaid code to a backend Laravel controller or headless Node service to render SVG.
- *Why rejected*: Violates MDVault's offline-first architecture; breaks for encrypted vaults (plaintext diagram content would have to be sent over the wire or decrypted in memory on server routes); introduces unnecessary latency and server load.

### Option 3: Two-Phase Hybrid Rendering with Lazy Dynamic Import & Strict Security (Selected)
- **Phase 1 (Sync Parsing)**: `renderMarkdownPreview()` in `markdownPreview.ts` parses ````mermaid```` code blocks synchronously and emits a clean, secure `.mermaid-block` container with the escaped source code in `data-mermaid-source` and fallback `<pre><code class="language-mermaid">` markup. This runs purely in Node during Vitest unit tests without browser dependencies.
- **Phase 2 (Async Browser Rendering)**: When rendered into the DOM in `MarkdownEditor.vue`, a browser-only renderer (`mermaidRenderer.ts`) dynamically imports `mermaid` on demand, compiles SVGs with `securityLevel: 'strict'`, and injects the rendered SVG into `.mermaid-container`.
- **Caching**: SVGs are cached in a client-side `Map` by `${theme}:${contentHash}` so subsequent keystrokes in Split view reuse existing SVGs instantly.
- **Theme Awareness**: A watcher on `isDark` re-triggers rendering with Mermaid's `'dark'` or `'default'` theme when the application theme changes.

---

## Decision
We select **Option 3: Two-Phase Hybrid Rendering with Lazy Dynamic Import & Strict Security**.

Specifically:
1. **Dependency**: Install `mermaid` via npm (requires user approval).
2. **Lazy Loading**: `loadMermaid()` performs `await import('mermaid')` only when `.mermaid-block` elements exist or when `MermaidDialog` is opened.
3. **Security**: Initialize Mermaid globally with `securityLevel: 'strict'` to disallow raw HTML script execution and click callbacks. All data attributes and fallback code are HTML-escaped.
4. **Editor Modal (`MermaidDialog.vue`)**: Provide a dedicated side-by-side editing dialog with presets, live validation, and error reporting, accessible from the toolbar (`Ctrl+Alt+D`) and from direct "Edit diagram" buttons on rendered diagrams.
5. **Node Test Isolation**: Keep `markdownPreview.ts` completely free of browser-only imports so Vitest tests run reliably under `node`.

---

## Consequences
- **Positive**: Initial bundle impact is 0 KB until a diagram is loaded; note rendering remains snappy; zero network requests for rendering; total privacy for encrypted vaults; full support for light/dark mode; 100% testable in Vitest.
- **Negative / Trade-off**: Adds an external npm dependency (`mermaid`); a brief skeleton transition occurs on the first render while Mermaid initializes.
