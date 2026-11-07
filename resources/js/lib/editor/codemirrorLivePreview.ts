import { syntaxTree } from '@codemirror/language';
import {
    type EditorState,
    type Extension,
    type Line,
    RangeSetBuilder,
    type Text,
} from '@codemirror/state';
import {
    Decoration,
    type DecorationSet,
    EditorView,
    ViewPlugin,
    type ViewUpdate,
    WidgetType,
} from '@codemirror/view';
import type { SyntaxNode, Tree } from '@lezer/common';
import { COPY_ICON_SVG, triggerCopyFeedback } from './copyButton';

export class CodeBlockCopyWidget extends WidgetType {
    constructor(readonly code: string) {
        super();
    }

    override eq(other: CodeBlockCopyWidget): boolean {
        return other.code === this.code;
    }

    override ignoreEvent(): boolean {
        return true;
    }

    override toDOM(_view: EditorView): HTMLElement {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'cm-md-copy-btn';
        button.setAttribute('aria-label', 'Copy code');
        button.setAttribute('title', 'Copy code');
        button.innerHTML = COPY_ICON_SVG;

        button.addEventListener('mousedown', (event) => {
            event.preventDefault();
            event.stopPropagation();
        });

        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            void triggerCopyFeedback(button, this.code);
        });

        return button;
    }
}

export class InlineCodeCopyWidget extends WidgetType {
    constructor(readonly code: string) {
        super();
    }

    override eq(other: InlineCodeCopyWidget): boolean {
        return other.code === this.code;
    }

    override ignoreEvent(): boolean {
        return true;
    }

    override toDOM(_view: EditorView): HTMLElement {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'cm-md-inline-copy-btn';
        button.setAttribute('aria-label', 'Copy code');
        button.setAttribute('title', 'Copy code');
        button.innerHTML = COPY_ICON_SVG;

        button.addEventListener('mousedown', (event) => {
            event.preventDefault();
            event.stopPropagation();
        });

        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            void triggerCopyFeedback(button, this.code);
        });

        return button;
    }
}

class TaskCheckboxWidget extends WidgetType {
    constructor(
        readonly checked: boolean,
        readonly pos: number,
        readonly readOnly: boolean,
    ) {
        super();
    }

    override eq(other: TaskCheckboxWidget): boolean {
        return (
            other.checked === this.checked &&
            other.pos === this.pos &&
            other.readOnly === this.readOnly
        );
    }

    override toDOM(view: EditorView): HTMLElement {
        const span = document.createElement('span');
        span.className = 'cm-md-task-widget';

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.checked = this.checked;
        input.disabled = this.readOnly;
        input.className = 'cm-md-task-checkbox';
        input.setAttribute(
            'aria-label',
            this.checked ? 'Mark task as not done' : 'Mark task as done',
        );

        // Handle the toggle entirely ourselves on `click` (after the default
        // `ignoreEvent()` — restored below — stops CodeMirror from treating
        // the mousedown as a selection gesture) so clicking never moves the
        // caret, and never writes to a read-only/frozen document (FR-17).
        input.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            if (this.readOnly || view.state.readOnly) {
                return;
            }

            const nextSyntax = this.checked ? '[ ]' : '[x]';
            view.dispatch({
                changes: {
                    from: this.pos,
                    to: this.pos + 3,
                    insert: nextSyntax,
                },
                userEvent: 'input.checkbox',
            });
            view.focus();
        });

        span.appendChild(input);
        return span;
    }
}

const FRONTMATTER_SCAN_LIMIT = 1000;

/**
 * `@codemirror/lang-markdown` has no concept of YAML frontmatter — a
 * leading `---\n...\n---` block parses as a `HorizontalRule` followed by a
 * `SetextHeading2` (same bug as FR-19). This bounded prefix scan detects it
 * directly instead, so frontmatter lines are excluded from decoration.
 * Returns the 1-based line number of the closing delimiter, or `0`.
 */
function computeFrontmatterEndLine(doc: Text): number {
    if (doc.lines < 2 || doc.line(1).text.trim() !== '---') {
        return 0;
    }

    const limit = Math.min(doc.lines, FRONTMATTER_SCAN_LIMIT);
    for (let i = 2; i <= limit; i++) {
        const text = doc.line(i).text.trim();
        if (text === '---' || text === '...') {
            return i;
        }
    }

    return 0;
}

function collectAncestors(tree: Tree, pos: number): SyntaxNode[] {
    const nodes: SyntaxNode[] = [];
    let node: SyntaxNode | null = tree.resolveInner(pos, 1);
    while (node) {
        nodes.push(node);
        node = node.parent;
    }
    return nodes;
}

function findAncestor(
    nodes: SyntaxNode[],
    name: string,
): SyntaxNode | undefined {
    return nodes.find((node) => node.type.name === name);
}

function findHeadingAncestor(nodes: SyntaxNode[]): SyntaxNode | undefined {
    return nodes.find((node) => /^ATXHeading[1-6]$/.test(node.type.name));
}

/**
 * Extracts only the inner code content of a fenced code block, excluding
 * the opening fence line and (if closed) closing fence delimiter line (FR-04).
 * Returns empty string if there are no inner lines.
 */
export function extractFencedCodeContent(
    state: EditorState,
    fencedCode: SyntaxNode,
): string {
    const startLineNumber = state.doc.lineAt(fencedCode.from).number;
    const closingMark =
        fencedCode.lastChild?.name === 'CodeMark' &&
        fencedCode.lastChild.from > fencedCode.from
            ? fencedCode.lastChild
            : null;

    const endLineNumber = closingMark
        ? state.doc.lineAt(closingMark.from).number - 1
        : state.doc.lineAt(Math.min(fencedCode.to, state.doc.length)).number;

    if (startLineNumber + 1 > endLineNumber) {
        return '';
    }

    const from = state.doc.line(startLineNumber + 1).from;
    const to = state.doc.line(endLineNumber).to;

    return state.sliceDoc(from, to);
}

/**
 * Extracts only the inner code content of an inline code span, excluding
 * the opening and closing CodeMark backtick delimiters (FR-09).
 */
export function extractInlineCodeContent(
    state: EditorState,
    inlineCode: SyntaxNode,
): string {
    const firstChild = inlineCode.firstChild;
    const lastChild = inlineCode.lastChild;

    if (
        firstChild &&
        lastChild &&
        firstChild !== lastChild &&
        firstChild.name === 'CodeMark' &&
        lastChild.name === 'CodeMark'
    ) {
        return state.sliceDoc(firstChild.to, lastChild.from);
    }

    const raw = state.sliceDoc(inlineCode.from, inlineCode.to);
    return raw.replace(/^`+|`+$/g, '');
}

function decorateFenceLine(
    builder: RangeSetBuilder<Decoration>,
    state: EditorState,
    fencedCode: SyntaxNode,
    line: Line,
): void {
    const startLineNumber = state.doc.lineAt(fencedCode.from).number;
    const endLineNumber = state.doc.lineAt(
        Math.min(fencedCode.to, state.doc.length),
    ).number;

    let cls = 'cm-md-fence-body';
    if (line.number === startLineNumber) {
        cls = 'cm-md-fence-open';
    } else if (line.number === endLineNumber) {
        cls = 'cm-md-fence-close';
    }

    builder.add(line.from, line.from, Decoration.line({ class: cls }));

    if (line.number === startLineNumber) {
        const code = extractFencedCodeContent(state, fencedCode);
        builder.add(
            line.to,
            line.to,
            Decoration.widget({
                widget: new CodeBlockCopyWidget(code),
                side: 1,
            }),
        );
    }
}

type LineDeco = {
    from: number;
    to: number;
    value: Decoration;
};

function decorateLine(
    builder: RangeSetBuilder<Decoration>,
    state: EditorState,
    tree: Tree,
    line: Line,
    frontmatterEndLine: number,
): void {
    if (line.number <= frontmatterEndLine) {
        return;
    }

    // Probe near the END of the line rather than its start: a list marker
    // (`- `) and its content node (e.g. `Task`) are siblings, not nested,
    // so a position right after the marker falls in the gap between them
    // and never resolves into `Task`. The line's own node (heading, quote,
    // rule, fenced code, task) always extends to cover its last character.
    const pos = line.text.length > 0 ? line.to - 1 : line.from;
    const ancestors = collectAncestors(tree, pos);

    const fencedCode = findAncestor(ancestors, 'FencedCode');
    if (fencedCode) {
        decorateFenceLine(builder, state, fencedCode, line);
        return;
    }

    if (findAncestor(ancestors, 'CodeBlock')) {
        // Indented code block: excluded from heading/quote/rule/task
        // decoration (FR-11), no fence styling to apply.
        return;
    }

    if (findAncestor(ancestors, 'HorizontalRule')) {
        builder.add(
            line.from,
            line.from,
            Decoration.line({ class: 'cm-md-rule' }),
        );
        return;
    }

    const lineDecos: LineDeco[] = [];

    const heading = findHeadingAncestor(ancestors);
    if (heading) {
        const level = Number(heading.type.name.slice('ATXHeading'.length));
        lineDecos.push({
            from: line.from,
            to: line.from,
            value: Decoration.line({ class: `cm-md-h${level}` }),
        });
    } else if (findAncestor(ancestors, 'Blockquote')) {
        lineDecos.push({
            from: line.from,
            to: line.from,
            value: Decoration.line({ class: 'cm-md-quote' }),
        });
    }

    const task = findAncestor(ancestors, 'Task');
    if (task) {
        const marker = task.getChild('TaskMarker');
        if (marker) {
            const isChecked = /x/i.test(state.sliceDoc(marker.from, marker.to));
            lineDecos.push({
                from: marker.from,
                to: marker.to,
                value: Decoration.replace({
                    widget: new TaskCheckboxWidget(
                        isChecked,
                        marker.from,
                        state.readOnly,
                    ),
                }),
            });
            if (isChecked && marker.to < line.to) {
                lineDecos.push({
                    from: marker.to,
                    to: line.to,
                    value: Decoration.mark({ class: 'cm-md-task-done' }),
                });
            }
        }
    }

    tree.iterate({
        from: line.from,
        to: line.to,
        enter(node) {
            if (node.name === 'InlineCode') {
                if (node.to >= line.from && node.to <= line.to) {
                    const code = extractInlineCodeContent(state, node.node);
                    lineDecos.push({
                        from: node.to,
                        to: node.to,
                        value: Decoration.widget({
                            widget: new InlineCodeCopyWidget(code),
                            side: 1,
                        }),
                    });
                }
                return false;
            }
        },
    });

    lineDecos.sort(
        (a, b) =>
            a.from - b.from ||
            a.value.startSide - b.value.startSide ||
            a.to - b.to,
    );

    for (const deco of lineDecos) {
        builder.add(deco.from, deco.to, deco.value);
    }
}

/**
 * Builds the Live-Preview decoration set for the given visible ranges.
 * Exported as a pure function (same pattern as `CommandTarget` in
 * `codemirrorCommands.ts`) so it is testable against a bare `EditorState`
 * under the `node` test environment, without mounting an `EditorView`.
 *
 * Iterates unique line numbers — tracking the last line processed across
 * every range — so `RangeSetBuilder` can never receive a decreasing `from`
 * when CodeMirror splits one long line across two visible ranges (FR-12).
 */
export function markdownLineDecorations(
    state: EditorState,
    ranges: readonly { from: number; to: number }[],
): DecorationSet {
    const builder = new RangeSetBuilder<Decoration>();
    const doc = state.doc;
    const tree = syntaxTree(state);
    const frontmatterEndLine = computeFrontmatterEndLine(doc);

    let lastLineProcessed = 0;

    for (const { from, to } of ranges) {
        let lineNumber = doc.lineAt(Math.min(from, doc.length)).number;
        if (lineNumber <= lastLineProcessed) {
            lineNumber = lastLineProcessed + 1;
        }
        const endLineNumber = doc.lineAt(Math.min(to, doc.length)).number;

        for (
            ;
            lineNumber <= endLineNumber && lineNumber <= doc.lines;
            lineNumber++
        ) {
            const line = doc.line(lineNumber);
            decorateLine(builder, state, tree, line, frontmatterEndLine);
            lastLineProcessed = lineNumber;
        }
    }

    return builder.finish();
}

export const livePreviewPlugin = ViewPlugin.fromClass(
    class {
        decorations: DecorationSet;

        constructor(view: EditorView) {
            this.decorations = markdownLineDecorations(
                view.state,
                view.visibleRanges,
            );
        }

        update(update: ViewUpdate): void {
            if (
                update.docChanged ||
                update.viewportChanged ||
                syntaxTree(update.startState) !== syntaxTree(update.state)
            ) {
                this.decorations = markdownLineDecorations(
                    update.view.state,
                    update.view.visibleRanges,
                );
            }
        }
    },
    {
        decorations: (v) => v.decorations,
    },
);

/**
 * All visual properties for the decorations above, expressed through
 * CodeMirror's own theme (FR-14) instead of Tailwind utility classes, which
 * lose to CodeMirror's unlayered CSS regardless of specificity. Heading
 * sizes are `em`-relative (FR-15, ADR §Decision-2) so they scale with the
 * `font_size` preference. No rule here sets `margin-top`/`margin-bottom`/
 * `margin` on `.cm-line` or any decoration class (FR-13) — spacing is
 * expressed as padding/border, which CodeMirror's line measurement includes.
 */
export function livePreview(): Extension {
    return [
        livePreviewPlugin,
        EditorView.theme({
            '.cm-md-h1': {
                fontWeight: '700',
                fontSize: '2em',
                lineHeight: '1.25',
                paddingBottom: '0.3em',
                borderBottom: '1px solid var(--border)',
            },
            '.cm-md-h2': {
                fontWeight: '700',
                fontSize: '1.5em',
                lineHeight: '1.3',
                paddingBottom: '0.25em',
                borderBottom: '1px solid var(--border)',
            },
            '.cm-md-h3': {
                fontWeight: '600',
                fontSize: '1.25em',
                lineHeight: '1.35',
            },
            '.cm-md-h4': {
                fontWeight: '600',
                fontSize: '1.1em',
            },
            '.cm-md-h5': {
                fontWeight: '500',
                fontSize: '1em',
            },
            '.cm-md-h6': {
                fontWeight: '500',
                fontSize: '0.9em',
                color: 'var(--muted-foreground)',
            },
            '.cm-md-quote': {
                borderLeft: '2px solid var(--primary)',
                paddingLeft: '0.75em',
                color: 'var(--muted-foreground)',
                fontStyle: 'italic',
            },
            '.cm-md-rule': {
                borderBottom: '1px solid var(--border)',
                paddingTop: '0.5em',
                paddingBottom: '0.5em',
                color: 'transparent',
            },
            '.cm-md-fence-open': {
                position: 'relative',
                paddingRight: '2.5rem',
                backgroundColor: 'var(--muted)',
                fontFamily:
                    'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
                fontSize: '0.9em',
                color: 'var(--muted-foreground)',
                borderTop: '1px solid var(--border)',
                borderLeft: '1px solid var(--border)',
                borderRight: '1px solid var(--border)',
                borderTopLeftRadius: '4px',
                borderTopRightRadius: '4px',
                paddingTop: '2px',
            },
            '.cm-md-copy-btn': {
                position: 'absolute',
                top: '3px',
                right: '6px',
                display: 'inline-flex',
                alignItems: 'center',
                justifyContent: 'center',
                width: '1.5rem',
                height: '1.5rem',
                padding: '0.2rem',
                border: '1px solid var(--border)',
                borderRadius: '3px',
                backgroundColor: 'var(--muted)',
                color: 'var(--muted-foreground)',
                cursor: 'pointer',
                opacity: '0',
                transition:
                    'opacity 150ms ease, color 150ms ease, background-color 150ms ease, border-color 150ms ease',
                userSelect: 'none',
                lineHeight: '1',
            },
            '.cm-line.cm-md-fence-open:hover .cm-md-copy-btn, .cm-md-fence-open:hover .cm-md-copy-btn, .cm-md-copy-btn:focus-visible, .cm-md-copy-btn.copied':
                {
                    opacity: '1',
                },
            '.cm-md-copy-btn:hover': {
                color: 'var(--foreground)',
                backgroundColor: 'var(--accent)',
            },
            '.cm-md-copy-btn.copied': {
                color: 'var(--foreground)',
                borderColor: 'var(--primary)',
            },
            '.cm-md-inline-copy-btn': {
                position: 'relative',
                display: 'inline-flex',
                alignItems: 'center',
                justifyContent: 'center',
                width: '1.1em',
                height: '1.1em',
                marginLeft: '0.25em',
                verticalAlign: 'middle',
                padding: '0.1em',
                border: '1px solid var(--border)',
                borderRadius: '3px',
                backgroundColor: 'var(--muted)',
                color: 'var(--muted-foreground)',
                cursor: 'pointer',
                opacity: '0',
                transition:
                    'opacity 150ms ease, color 150ms ease, background-color 150ms ease, border-color 150ms ease',
                userSelect: 'none',
                lineHeight: '1',
            },
            '.cm-line:hover .cm-md-inline-copy-btn, .cm-md-inline-copy-btn:focus-visible, .cm-md-inline-copy-btn.copied':
                {
                    opacity: '1',
                },
            '.cm-md-inline-copy-btn:hover': {
                color: 'var(--foreground)',
                backgroundColor: 'var(--accent)',
            },
            '.cm-md-inline-copy-btn.copied': {
                color: 'var(--foreground)',
                borderColor: 'var(--primary)',
            },
            '.cm-md-inline-copy-btn svg': {
                width: '0.75em',
                height: '0.75em',
            },
            '.cm-md-fence-body': {
                backgroundColor: 'var(--muted)',
                fontFamily:
                    'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
                fontSize: '0.9em',
                borderLeft: '1px solid var(--border)',
                borderRight: '1px solid var(--border)',
            },
            '.cm-md-fence-close': {
                backgroundColor: 'var(--muted)',
                fontFamily:
                    'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
                fontSize: '0.9em',
                color: 'var(--muted-foreground)',
                borderBottom: '1px solid var(--border)',
                borderLeft: '1px solid var(--border)',
                borderRight: '1px solid var(--border)',
                borderBottomLeftRadius: '4px',
                borderBottomRightRadius: '4px',
                paddingBottom: '2px',
            },
            '.cm-md-task-done': {
                textDecoration: 'line-through',
                color: 'var(--muted-foreground)',
            },
            '.cm-md-task-widget': {
                display: 'inline-flex',
                alignItems: 'center',
                verticalAlign: '-0.15em',
            },
            '.cm-md-task-checkbox': {
                cursor: 'pointer',
                margin: '0',
                marginRight: '0.1em',
                width: '1em',
                height: '1em',
                verticalAlign: 'middle',
                accentColor: 'var(--primary)',
                borderRadius: '0.25rem',
            },
        }),
    ];
}
