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
}

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

    const heading = findHeadingAncestor(ancestors);
    if (heading) {
        const level = Number(heading.type.name.slice('ATXHeading'.length));
        builder.add(
            line.from,
            line.from,
            Decoration.line({ class: `cm-md-h${level}` }),
        );
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

    if (findAncestor(ancestors, 'Blockquote')) {
        builder.add(
            line.from,
            line.from,
            Decoration.line({ class: 'cm-md-quote' }),
        );
    }

    const task = findAncestor(ancestors, 'Task');
    if (task) {
        const marker = task.getChild('TaskMarker');
        if (marker) {
            const isChecked = /x/i.test(state.sliceDoc(marker.from, marker.to));
            builder.add(
                marker.from,
                marker.to,
                Decoration.replace({
                    widget: new TaskCheckboxWidget(
                        isChecked,
                        marker.from,
                        state.readOnly,
                    ),
                }),
            );
            if (isChecked && marker.to < line.to) {
                builder.add(
                    marker.to,
                    line.to,
                    Decoration.mark({ class: 'cm-md-task-done' }),
                );
            }
        }
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
