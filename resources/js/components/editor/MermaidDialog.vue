<script setup lang="ts">
import type { EditorView } from '@codemirror/view';
import {
    AlertCircle,
    Check,
    Code,
    Copy,
    Image as ImageIcon,
    Loader2,
    Move,
    Scan,
    ZoomIn,
    ZoomOut,
} from '@lucide/vue';
import { refDebounced } from '@vueuse/core';
import { computed, nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { copyToClipboard } from '@/lib/editor/clipboard';
import { insertMermaidBlock } from '@/lib/editor/codemirrorCommands';
import {
    DEFAULT_MERMAID_VIEWPORT,
    MERMAID_ZOOM_STEP,
    formatMermaidTransform,
    stepMermaidZoom,
    type MermaidViewport,
} from '@/lib/editor/mermaidPanZoom';
import {
    copyMermaidSvg,
    renderMermaidDiagram,
} from '@/lib/editor/mermaidRenderer';
import {
    DEFAULT_MERMAID_CODE,
    MERMAID_TEMPLATES,
    getMermaidTemplate,
} from '@/lib/editor/mermaidTemplates';

const props = defineProps<{
    view?: EditorView | undefined;
    initialCode?: string;
    range?: { from: number; to: number } | undefined;
}>();

const emit = defineEmits<{
    apply: [code: string];
}>();

const open = defineModel<boolean>('open', { default: false });

const code = ref('');
const selectedTemplate = ref('');
const renderedSvg = ref('');
const syntaxError = ref<string | null>(null);
const isRendering = ref(false);
const copiedCode = ref(false);
const copiedSvg = ref(false);

const isDark = computed(() => {
    if (typeof document === 'undefined') {
        return false;
    }
    return document.documentElement.classList.contains('dark');
});

const isEditingExisting = computed(() => props.range !== undefined);

const isPanActive = ref(false);
const viewport = ref<MermaidViewport>({ ...DEFAULT_MERMAID_VIEWPORT });
const previewContainerRef = ref<HTMLDivElement | null>(null);
const isDragging = ref(false);
let startX = 0;
let startY = 0;
let startXOffset = 0;
let startYOffset = 0;

function applyPreviewTransform(withTransition = true): void {
    if (!previewContainerRef.value) {
        return;
    }
    const svg = previewContainerRef.value.querySelector<SVGElement>('svg');
    if (!svg) {
        return;
    }
    svg.style.transformOrigin = 'center center';
    svg.style.transition = withTransition ? 'transform 0.15s ease-out' : 'none';
    svg.style.transform = formatMermaidTransform(viewport.value);
}

function zoomIn(): void {
    viewport.value.zoom = stepMermaidZoom(
        viewport.value.zoom,
        MERMAID_ZOOM_STEP,
    );
    applyPreviewTransform(true);
}

function zoomOut(): void {
    viewport.value.zoom = stepMermaidZoom(
        viewport.value.zoom,
        -MERMAID_ZOOM_STEP,
    );
    applyPreviewTransform(true);
}

function resetView(): void {
    viewport.value = { ...DEFAULT_MERMAID_VIEWPORT };
    isPanActive.value = false;
    applyPreviewTransform(true);
}

function togglePan(): void {
    isPanActive.value = !isPanActive.value;
}

function handlePointerDown(event: PointerEvent): void {
    if (!isPanActive.value || !renderedSvg.value || syntaxError.value) {
        return;
    }
    if (event.button !== 0) {
        return;
    }
    event.preventDefault();
    isDragging.value = true;
    startX = event.clientX;
    startY = event.clientY;
    startXOffset = viewport.value.x;
    startYOffset = viewport.value.y;
    try {
        (event.currentTarget as HTMLElement)?.setPointerCapture?.(
            event.pointerId,
        );
    } catch {
        // ignore
    }
    applyPreviewTransform(false);
}

function handlePointerMove(event: PointerEvent): void {
    if (!isDragging.value) {
        return;
    }
    viewport.value.x = startXOffset + (event.clientX - startX);
    viewport.value.y = startYOffset + (event.clientY - startY);
    applyPreviewTransform(false);
}

function handlePointerUp(event: PointerEvent): void {
    if (isDragging.value) {
        isDragging.value = false;
        try {
            (event.currentTarget as HTMLElement)?.releasePointerCapture?.(
                event.pointerId,
            );
        } catch {
            // ignore
        }
        applyPreviewTransform(true);
    }
}

function handleWheel(event: WheelEvent): void {
    if (!renderedSvg.value || syntaxError.value) {
        return;
    }
    if (event.ctrlKey || event.metaKey) {
        event.preventDefault();
        const delta = event.deltaY < 0 ? MERMAID_ZOOM_STEP : -MERMAID_ZOOM_STEP;
        viewport.value.zoom = stepMermaidZoom(viewport.value.zoom, delta);
        applyPreviewTransform(true);
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        code.value = props.initialCode?.trim() || DEFAULT_MERMAID_CODE;
        selectedTemplate.value = '';
        syntaxError.value = null;
        resetView();
        triggerRender();
    }
});

watch(renderedSvg, async () => {
    await nextTick();
    applyPreviewTransform(false);
});

function onSelectTemplate(templateId: any): void {
    if (typeof templateId !== 'string') {
        return;
    }
    const template = getMermaidTemplate(templateId);
    if (template) {
        code.value = template.code;
        selectedTemplate.value = templateId;
        triggerRender();
    }
}

async function triggerRender(): Promise<void> {
    const trimmed = code.value.trim();
    if (!trimmed) {
        renderedSvg.value = '';
        syntaxError.value = null;
        return;
    }

    isRendering.value = true;
    try {
        const { svg } = await renderMermaidDiagram(trimmed, isDark.value);
        renderedSvg.value = svg;
        syntaxError.value = null;
    } catch (err: any) {
        syntaxError.value = err?.message || 'Invalid Mermaid syntax';
    } finally {
        isRendering.value = false;
    }
}

const debouncedCode = refDebounced(code, 200);

watch([debouncedCode, isDark], () => {
    if (open.value) {
        void triggerRender();
    }
});

function handleTextareaKeydown(event: KeyboardEvent): void {
    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
        event.preventDefault();
        apply();
        return;
    }

    if (event.key === 'Tab') {
        event.preventDefault();
        const textarea = event.target as HTMLTextAreaElement;
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const value = textarea.value;

        textarea.value =
            value.substring(0, start) + '    ' + value.substring(end);
        textarea.selectionStart = textarea.selectionEnd = start + 4;
        code.value = textarea.value;
    }
}

function apply(): void {
    const trimmed = code.value.trim();
    if (!trimmed) {
        open.value = false;
        return;
    }

    if (props.view) {
        insertMermaidBlock(props.view, trimmed, props.range);
    }

    emit('apply', trimmed);
    open.value = false;
}

async function copySvg(): Promise<void> {
    if (!renderedSvg.value) {
        return;
    }

    const ok = await copyMermaidSvg(renderedSvg.value);
    if (ok) {
        copiedSvg.value = true;
        setTimeout(() => {
            copiedSvg.value = false;
        }, 2000);
    }
}

async function copySource(): Promise<void> {
    if (!code.value) {
        return;
    }

    const ok = await copyToClipboard(code.value);
    if (ok) {
        copiedCode.value = true;
        setTimeout(() => {
            copiedCode.value = false;
        }, 2000);
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex max-h-[92vh] w-full max-w-5xl flex-col gap-4 overflow-hidden p-4 sm:p-6"
        >
            <DialogHeader class="shrink-0 space-y-1">
                <DialogTitle class="text-lg font-semibold">
                    Mermaid Diagram Editor
                </DialogTitle>
                <DialogDescription
                    class="text-xs text-muted-foreground sm:text-sm"
                >
                    Create, edit, and preview diagrams using Mermaid syntax.
                </DialogDescription>
            </DialogHeader>

            <!-- Template Picker Toolbar -->
            <div
                class="flex shrink-0 flex-wrap items-center justify-between gap-2 rounded-lg border border-border/60 bg-muted/30 px-3 py-2 text-xs"
            >
                <div class="flex items-center gap-2">
                    <span class="font-medium text-foreground">Preset:</span>
                    <Select
                        v-model="selectedTemplate"
                        @update:model-value="onSelectTemplate"
                    >
                        <SelectTrigger class="h-8 w-48 text-xs">
                            <SelectValue
                                placeholder="Choose a diagram type..."
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="tmpl in MERMAID_TEMPLATES"
                                :key="tmpl.id"
                                :value="tmpl.id"
                            >
                                {{ tmpl.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="flex items-center gap-1.5 text-muted-foreground">
                    <span class="hidden sm:inline">Shortcut:</span>
                    <kbd
                        class="rounded border border-border bg-muted px-1.5 py-0.5 font-mono text-[10px]"
                    >
                        Ctrl+Enter
                    </kbd>
                    <span class="hidden sm:inline">to insert</span>
                </div>
            </div>

            <!-- Side-by-side Editor and Live Preview -->
            <div
                class="grid min-h-0 flex-1 grid-cols-1 gap-4 overflow-y-auto md:grid-cols-2"
            >
                <!-- Editor Column -->
                <div class="flex flex-col gap-1.5">
                    <div class="flex items-center justify-between">
                        <Label
                            for="mermaid-code-input"
                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Diagram Source
                        </Label>
                        <span
                            class="font-mono text-[11px] text-muted-foreground"
                        >
                            {{ code.length }} chars
                        </span>
                    </div>
                    <textarea
                        id="mermaid-code-input"
                        v-model="code"
                        spellcheck="false"
                        class="min-h-[220px] flex-1 resize-none rounded-md border border-input bg-background p-3 font-mono text-xs leading-relaxed transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden sm:min-h-[340px]"
                        placeholder="Enter Mermaid diagram code..."
                        @keydown="handleTextareaKeydown"
                    />
                </div>

                <!-- Preview Column -->
                <div class="flex flex-col gap-1.5">
                    <div class="flex items-center justify-between">
                        <Label
                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Live Preview
                        </Label>
                        <div class="flex items-center gap-2">
                            <div
                                v-if="isRendering"
                                class="mr-1 flex items-center gap-1 text-[11px] text-muted-foreground"
                            >
                                <Loader2 class="size-3 animate-spin" />
                                <span>Updating...</span>
                            </div>
                            <div
                                class="flex items-center gap-0.5 rounded-md border border-border/60 bg-muted/40 p-0.5"
                            >
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="h-7 w-7"
                                    :class="{
                                        'border-primary bg-primary text-primary-foreground hover:bg-primary/90 hover:text-primary-foreground':
                                            isPanActive,
                                    }"
                                    aria-label="Toggle pan"
                                    title="Toggle pan"
                                    data-mermaid-pan
                                    :disabled="!renderedSvg || !!syntaxError"
                                    @click="togglePan"
                                >
                                    <Move class="size-3.5" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="h-7 w-7"
                                    aria-label="Zoom out"
                                    title="Zoom out"
                                    data-mermaid-zoom-out
                                    :disabled="!renderedSvg || !!syntaxError"
                                    @click="zoomOut"
                                >
                                    <ZoomOut class="size-3.5" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="h-7 w-7"
                                    aria-label="Zoom in"
                                    title="Zoom in"
                                    data-mermaid-zoom-in
                                    :disabled="!renderedSvg || !!syntaxError"
                                    @click="zoomIn"
                                >
                                    <ZoomIn class="size-3.5" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="h-7 w-7"
                                    aria-label="Reset view"
                                    title="Reset view"
                                    data-mermaid-reset
                                    :disabled="!renderedSvg || !!syntaxError"
                                    @click="resetView"
                                >
                                    <Scan class="size-3.5" />
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div
                        ref="previewContainerRef"
                        class="relative flex min-h-[220px] flex-1 flex-col items-center justify-center overflow-hidden rounded-md border border-border/80 bg-muted/20 p-4 transition-all sm:min-h-[340px]"
                        :class="{
                            'cursor-grab select-none':
                                isPanActive && !isDragging,
                            'cursor-grabbing select-none':
                                isPanActive && isDragging,
                        }"
                        @pointerdown="handlePointerDown"
                        @pointermove="handlePointerMove"
                        @pointerup="handlePointerUp"
                        @pointercancel="handlePointerUp"
                        @wheel="handleWheel"
                    >
                        <!-- Syntax Error Alert -->
                        <div
                            v-if="syntaxError"
                            class="w-full rounded-md border border-destructive/40 bg-destructive/10 p-3 text-xs text-destructive"
                        >
                            <div class="flex items-start gap-2">
                                <AlertCircle class="mt-0.5 size-4 shrink-0" />
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold">
                                        Mermaid Syntax Error
                                    </p>
                                    <p
                                        class="mt-1 font-mono text-[11px] whitespace-pre-wrap opacity-90"
                                    >
                                        {{ syntaxError }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Rendered SVG Diagram -->
                        <div
                            v-else-if="renderedSvg"
                            class="mermaid-dialog-svg flex max-h-full max-w-full items-center justify-center overflow-visible"
                            v-html="renderedSvg"
                        />

                        <!-- Empty Placeholder -->
                        <div
                            v-else
                            class="flex flex-col items-center justify-center text-center text-xs text-muted-foreground"
                        >
                            <Code class="mb-1.5 size-6 opacity-40" />
                            <p>No diagram to display</p>
                            <p class="text-[11px] text-muted-foreground/80">
                                Type Mermaid syntax or pick a template above.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <DialogFooter
                class="flex shrink-0 flex-col-reverse items-center justify-between gap-2 sm:flex-row"
            >
                <!-- Quick Copy Actions -->
                <div class="flex w-full items-center gap-1.5 sm:w-auto">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="h-8 text-xs"
                        :disabled="!code.trim()"
                        @click="copySource"
                    >
                        <component
                            :is="copiedCode ? Check : Copy"
                            class="mr-1.5 size-3.5"
                        />
                        {{ copiedCode ? 'Copied' : 'Copy Code' }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="h-8 text-xs"
                        :disabled="!renderedSvg || !!syntaxError"
                        @click="copySvg"
                    >
                        <component
                            :is="copiedSvg ? Check : ImageIcon"
                            class="mr-1.5 size-3.5"
                        />
                        {{ copiedSvg ? 'Copied' : 'Copy SVG' }}
                    </Button>
                </div>

                <!-- Dialog Actions -->
                <div
                    class="flex w-full items-center justify-end gap-2 sm:w-auto"
                >
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="h-8 text-xs"
                        @click="open = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        class="h-8 text-xs"
                        :disabled="!code.trim() || !!syntaxError"
                        @click="apply"
                    >
                        {{
                            isEditingExisting
                                ? 'Update Diagram'
                                : 'Insert Diagram'
                        }}
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<style>
.mermaid-dialog-svg svg {
    max-width: 100%;
    height: auto;
    display: block;
    transform-origin: center center;
    transition: transform 0.15s ease-out;
}
</style>
