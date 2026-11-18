<script setup lang="ts">
import { RotateCcw, X, ZoomIn, ZoomOut } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DEFAULT_MERMAID_VIEWPORT,
    MERMAID_PAN_STEP,
    MERMAID_ZOOM_STEP,
    formatMermaidTransform,
    formatMermaidZoomPercent,
    stepMermaidPan,
    stepMermaidZoom,
    type MermaidViewport,
} from '@/lib/editor/mermaidPanZoom';
import { renderMermaidDiagram } from '@/lib/editor/mermaidRenderer';

const props = withDefaults(
    defineProps<{
        source?: string;
        initialSvg?: string;
        isDark?: boolean;
    }>(),
    {
        source: '',
        initialSvg: '',
        isDark: false,
    },
);

const open = defineModel<boolean>('open', { default: false });

const renderedSvg = ref('');
const viewport = ref<MermaidViewport>({ ...DEFAULT_MERMAID_VIEWPORT });
const isDragging = ref(false);

let startX = 0;
let startY = 0;
let startOffsetX = 0;
let startOffsetY = 0;
let renderRequestId = 0;

const zoomPercent = computed(() =>
    formatMermaidZoomPercent(viewport.value.zoom),
);

const svgWrapperStyle = computed(() => ({
    transform: formatMermaidTransform(viewport.value),
    transformOrigin: 'center center',
    transition: isDragging.value ? 'none' : 'transform 0.15s ease-out',
}));

function resetView(): void {
    viewport.value = { ...DEFAULT_MERMAID_VIEWPORT };
    isDragging.value = false;
}

function zoomIn(): void {
    viewport.value = {
        ...viewport.value,
        zoom: stepMermaidZoom(viewport.value.zoom, MERMAID_ZOOM_STEP),
    };
}

function zoomOut(): void {
    viewport.value = {
        ...viewport.value,
        zoom: stepMermaidZoom(viewport.value.zoom, -MERMAID_ZOOM_STEP),
    };
}

async function renderDiagramForTheme(dark: boolean): Promise<void> {
    const trimmed = props.source.trim();
    if (!trimmed) {
        return;
    }

    const requestId = ++renderRequestId;
    try {
        const { svg } = await renderMermaidDiagram(trimmed, dark);
        if (!open.value || requestId !== renderRequestId) {
            return;
        }
        renderedSvg.value = svg;
    } catch {
        // Keep existing SVG if theme re-render fails
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        renderRequestId++;
        resetView();
        renderedSvg.value = props.initialSvg;
        if (!props.initialSvg && props.source.trim()) {
            void renderDiagramForTheme(props.isDark);
        }
    } else {
        renderRequestId++;
        isDragging.value = false;
    }
});

watch(
    () => props.isDark,
    (dark) => {
        if (open.value && props.source.trim()) {
            void renderDiagramForTheme(dark);
        }
    },
);

function handlePointerDown(event: PointerEvent): void {
    if (!renderedSvg.value || event.button !== 0) {
        return;
    }
    event.preventDefault();
    isDragging.value = true;
    startX = event.clientX;
    startY = event.clientY;
    startOffsetX = viewport.value.x;
    startOffsetY = viewport.value.y;

    try {
        (event.currentTarget as HTMLElement | null)?.setPointerCapture?.(
            event.pointerId,
        );
    } catch {
        // Ignore pointer capture failures in unsupported environments
    }
}

function handlePointerMove(event: PointerEvent): void {
    if (!isDragging.value) {
        return;
    }
    viewport.value = {
        ...viewport.value,
        x: startOffsetX + (event.clientX - startX),
        y: startOffsetY + (event.clientY - startY),
    };
}

function handlePointerUp(event: PointerEvent): void {
    if (!isDragging.value) {
        return;
    }
    isDragging.value = false;
    try {
        (event.currentTarget as HTMLElement | null)?.releasePointerCapture?.(
            event.pointerId,
        );
    } catch {
        // Ignore pointer capture release failures
    }
}

function handleWheel(event: WheelEvent): void {
    if (!renderedSvg.value || event.deltaY === 0) {
        return;
    }
    event.preventDefault();
    const delta = event.deltaY < 0 ? MERMAID_ZOOM_STEP : -MERMAID_ZOOM_STEP;
    viewport.value = {
        ...viewport.value,
        zoom: stepMermaidZoom(viewport.value.zoom, delta),
    };
}

function handleDialogKeydown(event: KeyboardEvent): void {
    if (!open.value) {
        return;
    }

    if (event.key === '+' || event.key === '=') {
        event.preventDefault();
        zoomIn();
        return;
    }

    if (event.key === '-' || event.key === '_') {
        event.preventDefault();
        zoomOut();
        return;
    }

    if (event.key === '0') {
        event.preventDefault();
        resetView();
        return;
    }

    const target = event.target as HTMLElement | null;
    if (target?.closest?.('button, input, textarea, select')) {
        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        viewport.value = stepMermaidPan(viewport.value, 0, MERMAID_PAN_STEP);
    } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        viewport.value = stepMermaidPan(viewport.value, 0, -MERMAID_PAN_STEP);
    } else if (event.key === 'ArrowLeft') {
        event.preventDefault();
        viewport.value = stepMermaidPan(viewport.value, MERMAID_PAN_STEP, 0);
    } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        viewport.value = stepMermaidPan(viewport.value, -MERMAID_PAN_STEP, 0);
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            :show-close-button="false"
            class="flex h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-none flex-col gap-0 overflow-hidden p-0 sm:h-[calc(100dvh-3rem)] sm:w-[calc(100vw-3rem)] sm:max-w-none"
            @keydown="handleDialogKeydown"
        >
            <DialogHeader class="sr-only">
                <DialogTitle>Fullscreen Mermaid Diagram</DialogTitle>
                <DialogDescription>
                    Inspect, pan, and zoom the rendered Mermaid diagram.
                </DialogDescription>
            </DialogHeader>

            <!-- Top-left floating zoom & reset controls -->
            <div
                class="absolute top-3 left-3 z-20 flex items-center gap-1 rounded-lg border border-border/70 bg-background/90 p-1 shadow-sm backdrop-blur-xs"
            >
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    data-fullscreen-zoom-out
                    aria-label="Zoom out"
                    title="Zoom out (-)"
                    :disabled="!renderedSvg"
                    @click="zoomOut"
                >
                    <ZoomOut class="size-4" />
                </Button>
                <span
                    data-fullscreen-zoom-indicator
                    aria-label="Zoom level"
                    class="min-w-12 text-center font-mono text-xs font-medium text-foreground tabular-nums select-none"
                >
                    {{ zoomPercent }}
                </span>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    data-fullscreen-zoom-in
                    aria-label="Zoom in"
                    title="Zoom in (+)"
                    :disabled="!renderedSvg"
                    @click="zoomIn"
                >
                    <ZoomIn class="size-4" />
                </Button>
                <span class="mx-0.5 h-4 w-px bg-border/60" aria-hidden="true" />
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="h-8 gap-1.5 px-2.5 text-xs"
                    data-fullscreen-reset
                    aria-label="Reset view"
                    title="Reset view (0)"
                    :disabled="!renderedSvg"
                    @click="resetView"
                >
                    <RotateCcw class="size-3.5" />
                    <span>Reset</span>
                </Button>
            </div>

            <!-- Top-right floating close button -->
            <div class="absolute top-3 right-3 z-20">
                <DialogClose as-child>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        data-fullscreen-close
                        aria-label="Close fullscreen"
                        title="Close (Esc)"
                    >
                        <X class="size-4" />
                    </Button>
                </DialogClose>
            </div>

            <!-- Interactive pan & zoom canvas -->
            <div
                class="flex min-h-0 flex-1 items-center justify-center overflow-hidden bg-card/40 p-6 select-none"
                :class="{
                    'cursor-grab': renderedSvg && !isDragging,
                    'cursor-grabbing': renderedSvg && isDragging,
                }"
                style="touch-action: none"
                @pointerdown="handlePointerDown"
                @pointermove="handlePointerMove"
                @pointerup="handlePointerUp"
                @pointercancel="handlePointerUp"
                @wheel="handleWheel"
            >
                <div
                    v-if="renderedSvg"
                    class="mermaid-fullscreen-svg flex max-h-full max-w-full items-center justify-center overflow-visible"
                    :style="svgWrapperStyle"
                    v-html="renderedSvg"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>

<style>
.mermaid-fullscreen-svg svg {
    max-width: 100%;
    max-height: calc(100dvh - 8rem);
    height: auto;
    display: block;
}
</style>
