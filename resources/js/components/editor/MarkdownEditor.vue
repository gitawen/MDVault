<script setup lang="ts">
import { indentUnit, syntaxHighlighting } from '@codemirror/language';
import { EditorState } from '@codemirror/state';
import { EditorView, lineNumbers } from '@codemirror/view';
import { FileText } from '@lucide/vue';
import { refDebounced } from '@vueuse/core';
import {
    computed,
    nextTick,
    onMounted,
    onUnmounted,
    ref,
    shallowRef,
    watch,
} from 'vue';
import EditorToolbar from '@/components/editor/EditorToolbar.vue';
import { useNoteNavigation } from '@/composables/useNoteNavigation';
import {
    buildCodeMirrorExtensions,
    buildTypographyTheme,
    createCodeMirrorCompartments,
} from '@/lib/editor/codemirror';
import {
    appEditorTheme,
    appHighlightStyle,
} from '@/lib/editor/codemirrorTheme';
import {
    isAllowedHref,
    renderMarkdownPreview,
    stripFrontmatter,
} from '@/lib/editor/markdownPreview';
import type { EditorPreferences } from '@/types';

type DisplayMode = 'code' | 'split' | 'preview';

const {
    editable = true,
    readonly = false,
    preferences,
    noteUuid,
} = defineProps<{
    editable?: boolean;
    readonly?: boolean;
    preferences: EditorPreferences;
    noteUuid?: string;
}>();

const { noteSelectionTick } = useNoteNavigation();

const emit = defineEmits<{
    change: [];
}>();

const text = defineModel<string>({ default: '' });

const editorContainer = ref<HTMLDivElement | null>(null);
const view = shallowRef<EditorView | null>(null);
const displayMode = ref<DisplayMode>(preferences.default_view ?? 'code');
const toolbarRef = ref<InstanceType<typeof EditorToolbar> | null>(null);

// Bumped by the CodeMirror `onUpdate` callback on every doc/selection/focus
// change, so `EditorToolbar`'s active/enabled state actually re-renders in
// CodeMirror mode (FR-01/FR-02) instead of freezing at the initial mount.
const revision = ref(0);

const compartments = createCodeMirrorCompartments();

function checkIsDark(): boolean {
    if (typeof document === 'undefined') {
        return false;
    }

    return document.documentElement.classList.contains('dark');
}

const isDark = ref(checkIsDark());
let themeObserver: MutationObserver | null = null;

onMounted(() => {
    if (typeof MutationObserver !== 'undefined') {
        themeObserver = new MutationObserver(() => {
            const dark = checkIsDark();
            if (dark !== isDark.value) {
                isDark.value = dark;
            }
        });
        themeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });
    }

    if (!editorContainer.value) {
        return;
    }

    const state = EditorState.create({
        doc: text.value,
        extensions: buildCodeMirrorExtensions({
            compartments,
            preferences,
            editable,
            readonly,
            isDark: isDark.value,
            onChange: (newDoc) => {
                if (text.value !== newDoc) {
                    text.value = newDoc;
                    emit('change');
                }
            },
            onUpdate: () => {
                revision.value++;
            },
            onLink: () => {
                toolbarRef.value?.openLinkDialog();
            },
        }),
    });

    view.value = new EditorView({
        state,
        parent: editorContainer.value,
    });
});

onUnmounted(() => {
    themeObserver?.disconnect();
    view.value?.destroy();
    view.value = null;
});

// Sync external text changes (e.g. reload or reset) into CodeMirror
watch(text, (newVal) => {
    if (!view.value) {
        return;
    }
    const currentVal = view.value.state.doc.toString();
    if (currentVal !== newVal) {
        view.value.dispatch({
            changes: { from: 0, to: currentVal.length, insert: newVal },
        });
    }
});

// React to editable / readonly changes
watch([() => editable, () => readonly], ([ed, ro]) => {
    if (!view.value) {
        return;
    }
    const isEditable = ed && !ro;
    view.value.dispatch({
        effects: compartments.editable.reconfigure([
            EditorView.editable.of(isEditable),
            EditorState.readOnly.of(!isEditable),
            EditorView.theme({
                '&': {
                    cursor: isEditable ? 'text' : 'default',
                },
            }),
        ]),
    });
});

// React to theme changes (dark mode)
watch(isDark, (dark) => {
    if (!view.value) {
        return;
    }
    view.value.dispatch({
        effects: compartments.theme.reconfigure([
            appEditorTheme(dark),
            syntaxHighlighting(appHighlightStyle(dark)),
        ]),
    });
});

// React to preferences changes
watch(
    () => preferences,
    (prefs) => {
        if (!view.value) {
            return;
        }
        view.value.dispatch({
            effects: [
                compartments.lineNumbers.reconfigure(
                    prefs.show_line_numbers ? lineNumbers() : [],
                ),
                compartments.wordWrap.reconfigure(
                    prefs.word_wrap !== false ? EditorView.lineWrapping : [],
                ),
                compartments.indentUnit.reconfigure(
                    indentUnit.of(' '.repeat(prefs.indent_size ?? 4)),
                ),
                compartments.typography.reconfigure(
                    buildTypographyTheme(prefs),
                ),
            ],
        });
    },
    { deep: true },
);

// When switching to or from split/preview, trigger CodeMirror re-measure
watch(displayMode, (mode) => {
    if (mode !== 'preview' && view.value) {
        void nextTick(() => {
            view.value?.requestMeasure();
        });
    }
});

function getText(): string {
    return view.value ? view.value.state.doc.toString() : text.value;
}

const fontFamilyClass = computed(() => {
    switch (preferences.font_family) {
        case 'serif':
            return 'font-serif';
        case 'mono':
            return 'font-mono';
        default:
            return 'font-sans';
    }
});

const previewTypographyStyle = computed(() => ({
    fontSize: `${preferences.font_size}px`,
    lineHeight: String(preferences.line_height),
}));

// Debounce the preview render (200ms) so Split view no longer re-parses the
// whole document with `marked` on every keystroke (FR-21).
const debouncedText = refDebounced(text, 200);

const renderedHtml = computed(() => {
    if (displayMode.value === 'code') {
        return '';
    }
    const source = debouncedText.value;
    if (!source.trim()) {
        return '';
    }
    try {
        return renderMarkdownPreview(stripFrontmatter(source));
    } catch {
        return '<p class="text-destructive font-medium">Failed to render Markdown preview.</p>';
    }
});

function handlePreviewClick(event: MouseEvent): void {
    const anchor = (event.target as HTMLElement | null)?.closest('a[href]');
    if (!anchor || !(anchor instanceof HTMLAnchorElement)) {
        return;
    }

    // Always prevent the browser's own navigation first (FR-20): falling
    // through to the default action for a disallowed protocol is exactly
    // how `javascript:`/`data:` hrefs used to execute.
    event.preventDefault();

    if (isAllowedHref(anchor.href)) {
        window.open(anchor.href, '_blank', 'noopener,noreferrer');
    }
}

function resetDisplayMode(): void {
    displayMode.value = preferences.default_view ?? 'code';
}

watch(noteSelectionTick, () => {
    resetDisplayMode();
});

watch(
    () => noteUuid,
    () => {
        resetDisplayMode();
    },
);

watch(
    () => preferences.default_view,
    () => {
        resetDisplayMode();
    },
);

defineExpose({ getText, resetDisplayMode });
</script>

<template>
    <div
        class="flex min-h-0 flex-1 flex-col rounded-xl border border-border/80 bg-card shadow-xs transition-all duration-200 focus-within:border-primary/40 focus-within:ring-1 focus-within:ring-primary/20"
    >
        <!-- Formatting toolbar -->
        <EditorToolbar
            ref="toolbarRef"
            :view="view ?? undefined"
            :revision="revision"
            v-model:display-mode="displayMode"
        />

        <!-- Main Workspace (Editor / Split / Preview) -->
        <div
            class="flex min-h-0 w-full flex-1 flex-col overflow-hidden rounded-b-xl sm:flex-row"
        >
            <!-- Editor Pane -->
            <div
                v-show="displayMode === 'code' || displayMode === 'split'"
                ref="editorContainer"
                class="min-h-0 w-full min-w-0 flex-1 overflow-hidden"
                :class="{
                    'border-b border-border/60 sm:border-r sm:border-b-0':
                        displayMode === 'split',
                }"
                aria-label="Note editor"
            />

            <!-- Markdown Preview Pane -->
            <div
                v-if="displayMode === 'preview' || displayMode === 'split'"
                class="preview-scroll-container min-h-0 w-full min-w-0 flex-1 overflow-y-auto bg-card/60 p-4 sm:p-6 md:p-8"
                :class="fontFamilyClass"
                :style="previewTypographyStyle"
                @click="handlePreviewClick"
            >
                <!-- Empty state placeholder -->
                <div
                    v-if="!text.trim()"
                    class="flex h-full flex-col items-center justify-center py-16 text-muted-foreground/60 select-none"
                >
                    <FileText class="mb-2 h-9 w-9 opacity-40" />
                    <p class="text-sm font-medium">No content to preview</p>
                    <p class="mt-1 text-xs text-muted-foreground/80">
                        Type in the editor to see your Markdown rendered live.
                    </p>
                </div>

                <!-- Curated Markdown Viewer Container -->
                <div
                    v-else
                    class="markdown-preview-content markdown-content mx-auto max-w-3xl"
                    v-html="renderedHtml"
                />
            </div>
        </div>
    </div>
</template>

<style>
/* CodeMirror full container fill */
.cm-editor {
    height: 100%;
}

/* Dedicated styling for GFM rendered preview elements */
.markdown-preview-content {
    word-break: break-word;
}

.markdown-preview-content table {
    display: table;
    width: 100%;
    border-collapse: collapse;
    margin: 1.25rem 0;
    font-size: 0.9em;
}

.markdown-preview-content th,
.markdown-preview-content td {
    border: 1px solid var(--border);
    padding: 0.5rem 0.85rem;
    text-align: left;
}

.markdown-preview-content th {
    background-color: var(--muted);
    font-weight: 600;
}

/* GFM Task List Checkboxes */
.markdown-preview-content li:has(input[type='checkbox']) {
    list-style-type: none;
    position: relative;
    padding-left: 1.5rem;
    margin-left: -1.5rem;
}

.markdown-preview-content li:has(input[type='checkbox']) > p {
    margin-top: 0;
    margin-bottom: 0;
}

.markdown-preview-content input[type='checkbox'] {
    position: absolute;
    left: 0;
    top: 0.28em;
    margin: 0;
    width: 1em;
    height: 1em;
    accent-color: var(--primary);
    border-radius: 0.25rem;
    cursor: default;
}

.markdown-preview-content li:has(> input[type='checkbox']:checked),
.markdown-preview-content li:has(> p > input[type='checkbox']:checked) {
    text-decoration: line-through;
    color: var(--muted-foreground);
}

.markdown-preview-content li:has(> input[type='checkbox']:checked) strong,
.markdown-preview-content li:has(> p > input[type='checkbox']:checked) strong {
    color: inherit;
}

/* Smooth scrollbar for preview */
.preview-scroll-container {
    scrollbar-gutter: stable;
}
</style>
