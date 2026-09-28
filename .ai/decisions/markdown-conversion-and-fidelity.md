# ADR: Markdown conversion location, library and round-trip fidelity

- **Status**: Proposed (Phase 4 plan; pending user approvals F1, F2, F3, F10)
- **Date**: 2026-09-29
- **Phase**: Master Plan Phase 4, Tiptap Editor (§2, §20, §21, §41, §42, §53; Rules 1, 5, 8; §61 item 16)

## Context
- Tiptap and ProseMirror run in the browser. The editor state is ProseMirror JSON. §2 requires Tiptap → Markdown serializer → `.md` file, and the reverse.
- §41 assigns "Markdown → Tiptap, Tiptap → Markdown, Markdown validation" to `MarkdownService`. Rule 5 and §42 keep filesystem work out of Vue. Conversion itself touches no files.
- No PHP library can produce ProseMirror JSON from Markdown with Tiptap's schema. The only server path is league/commonmark (Markdown → HTML) plus an HTML → Markdown converter, which normalises markers, escapes and fences, loses task-list state unless extended, and is lossy by construction.
- Tiptap 3.31.3 is installed. The official `@tiptap/markdown` 3.31.3 (lexer `marked` ^17, peers `@tiptap/core`/`@tiptap/pm` 3.31.3) gives bidirectional conversion through `editor.getMarkdown()`, `contentType: 'markdown'` and a DOM-free `MarkdownManager`. Upstream describes it as an early release.
- Every Markdown → model → Markdown pipeline normalises syntax: list markers, emphasis characters, indentation, setext headings, escapes. User notes come from other tools and contain syntax the §20 toolbar has no node for: tables, images, HTML, footnotes, reference links, wiki links.
- Tiptap's Underline serialises as `++text++`, and its inline tokenizer matches `C++ … C++`. TextAlign has no Markdown serialisation.
- Vite+ 0.3.0 is already a dev dependency and bundles Vitest 4.1.11.

## Options Considered
1. **Where conversion runs**:
   - (a) **client, with a server-side envelope service (chosen)**;
   - (b) server (lossy; would need HTML on the wire);
   - (c) client only, including BOM/EOL/frontmatter (these would round-trip through JSON and the textarea, and the server would have to trust them).
2. **Library**:
   - (a) **`@tiptap/markdown@^3.31.3` (chosen)**: official, released on the same version train as Tiptap;
   - (b) `tiptap-markdown@0.9.0`: community; markdown-it + prosemirror-markdown; a mature serializer, but it trails releases;
   - (c) a hand-rolled prosemirror-markdown mapping.
3. **Fidelity strategy**:
   - (a) edit everything in rich text and accept normalisation (silent rewrites; rejected by Rule 8 and the "only write on a real edit" requirement);
   - (b) unsupported notes read-only (safe but blocks editing);
   - (c) **a check on open, with a consent step for reformat and lossless Source mode for unsupported (chosen)**;
   - (d) a warning at save time (conflicts with autosave).
4. **Underline and alignment**:
   - (a) **omit (chosen)**;
   - (b) `++`;
   - (c) HTML passthrough.

## Decision
- **Split of responsibility**:
  - Client module `resources/js/lib/markdown/`:
    - `extensions.ts` is the single extension list, used by the visible editor, the headless converter and the tests: StarterKit with `underline: false`, `trailingNode: false`, `link.openOnClick: false`; TaskList/TaskItem; Markdown with GFM and 2-space indentation.
    - `converter.ts` does headless parse and serialize.
    - `assess.ts` is the fidelity check.

    Serializer overrides live only in `extensions.ts`, and each has a fixture.
  - Server `MarkdownService` (pure strings, no filesystem):
    - `decode` detects the BOM, the dominant EOL and UTF-8 validity, normalises to LF, and splits leading YAML frontmatter (including the blank lines that follow it);
    - `composeRich` re-attaches the current frontmatter and applies the trailing-newline policy;
    - `composeSource` is verbatim;
    - `encode` re-applies the EOL and BOM.

    Tiptap only ever sees an LF body with no BOM and no frontmatter.
- **Fidelity check** (`assessMarkdown`), in order:
  1. Structural lexer tokens `table`, `html`/`tag`, `image`, `def`, or reference definitions → `unsupported`.
  2. Round trip equals the body (ignoring trailing newlines) → `exact`.
  3. Footnote or wiki-link patterns → `unsupported`.
  4. The round trip is idempotent **and** parses to a deep-equal document → `reformat` (reporting the first differing line); otherwise → `unsupported('unstable')`.
- **UX consequences**:
  - `exact` → rich and editable;
  - `reformat` → rich read-only until the user accepts reformatting (per note, per session), or chooses Source;
  - `unsupported` → Source only.

  Source mode is a plain textarea over the whole file and is lossless.
- **No write without an edit**: the save baseline is the visible editor's own serialisation at creation (Rich) or the loaded text (Source). Content equal to the baseline is never sent. The server skips identical bytes.
- **Toolbar**: §20 minus underline and alignment. Heading levels 1–6 stay in the schema, so notes with H4–H6 aren't lossy; the toolbar offers H1–H3.
- **Testing**: Vitest through `vp test run` (`npm run test:js`), with fixture folders `exact/`, `reformat/` (with expected outputs) and `unsupported/` under `tests/js/fixtures/markdown/`. Invariants: byte-exactness for `exact`, idempotence for all, document equality for `reformat`. Library code uses relative imports so tests need no Vite aliases. `happy-dom` is added only if the headless editor needs a DOM.
- **Go/no-go**: if the core set (headings, emphasis, strike, inline code, links, bullet, ordered and task lists, blockquote, fenced code, rule) can't round-trip idempotently into standard CommonMark/GFM, implementation stops and this ADR is revisited, with `tiptap-markdown@0.9.0` as the candidate.

## Consequences
- **Positive**:
  - Markdown stays the source of truth.
  - A note is never rewritten on open. Rewrites happen only on the user's edit, and only when provably lossless at the document level, or verbatim (Source).
  - Official packages stay on one version train.
  - The envelope (BOM, CRLF, frontmatter) is byte-preserved by server code that is fully covered by Pest.
- **Negative / trade-offs**:
  - §41's "Markdown → Tiptap" moves to the client. `MarkdownService` covers only the envelope and validation.
  - Two test runners (Pest and Vitest).
  - Notes from other tools often need one consent click.
  - Tables and images can be edited only as source.
  - Underline and alignment from §20/§53 aren't offered (a deliberate deviation per §20).
  - Relies on an early-release upstream package, mitigated by the check and the fixtures.
- **Follow-ups**:
  - Tables and images (§20 "later"), once a Markdown-exact node exists; they would move from `unsupported` to supported with fixtures.
  - An optional "always allow reformatting" setting.
  - A line-numbers gutter in Source mode.
  - Opening links externally through the NativePHP shell.
  - Phase 5 may add a Compare view that reuses `firstDifference`.
