/**
 * Viewport state representing pan coordinates (x, y) and zoom scale factor.
 */
export interface MermaidViewport {
    zoom: number;
    x: number;
    y: number;
}

export const MERMAID_ZOOM_MIN = 0.25;
export const MERMAID_ZOOM_MAX = 4.0;
export const MERMAID_ZOOM_STEP = 0.2;

export const DEFAULT_MERMAID_VIEWPORT: Readonly<MermaidViewport> =
    Object.freeze({
        zoom: 1,
        x: 0,
        y: 0,
    });

/**
 * Clamps zoom value between min and max bounds, rounding to 2 decimal places.
 * Safe to execute in Node.js or browser.
 */
export function clampMermaidZoom(
    zoom: number,
    min: number = MERMAID_ZOOM_MIN,
    max: number = MERMAID_ZOOM_MAX,
): number {
    if (!Number.isFinite(zoom)) {
        return 1;
    }
    const clamped = Math.min(Math.max(zoom, min), max);
    return Math.round(clamped * 100) / 100;
}

/**
 * Calculates a new zoom level by applying delta to currentZoom, clamped to [min, max].
 * Safe to execute in Node.js or browser.
 */
export function stepMermaidZoom(
    currentZoom: number,
    delta: number,
    min: number = MERMAID_ZOOM_MIN,
    max: number = MERMAID_ZOOM_MAX,
): number {
    const base = Number.isFinite(currentZoom) ? currentZoom : 1;
    return clampMermaidZoom(base + delta, min, max);
}

/**
 * Formats CSS transform string from a viewport object.
 * Returns: `translate(${x}px, ${y}px) scale(${scale})`
 * Safe to execute in Node.js or browser.
 */
export function formatMermaidTransform(
    viewport: Partial<MermaidViewport>,
): string {
    const x = Number.isFinite(viewport.x)
        ? Math.round((viewport.x as number) * 10) / 10
        : 0;
    const y = Number.isFinite(viewport.y)
        ? Math.round((viewport.y as number) * 10) / 10
        : 0;
    const zoom = Number.isFinite(viewport.zoom)
        ? clampMermaidZoom(viewport.zoom as number)
        : 1;
    return `translate(${x}px, ${y}px) scale(${zoom})`;
}

const blockViewports = new WeakMap<HTMLElement, MermaidViewport>();
const blockPanActive = new WeakMap<HTMLElement, boolean>();
const blockCleanupFns = new WeakMap<HTMLElement, () => void>();

export function resolveMermaidBlock(el: HTMLElement): HTMLElement | null {
    if (!el) {
        return null;
    }
    return el.classList?.contains('mermaid-block')
        ? el
        : (el.closest?.<HTMLElement>('.mermaid-block') ?? null);
}

export function resolveMermaidSvg(
    target: HTMLElement | SVGElement | null | undefined,
): SVGElement | null {
    if (!target) {
        return null;
    }

    if (
        (typeof SVGElement !== 'undefined' &&
            target instanceof SVGElement &&
            target.tagName.toLowerCase() === 'svg') ||
        target.tagName?.toLowerCase() === 'svg'
    ) {
        return target as SVGElement;
    }

    const el = target as HTMLElement;

    if (el.classList?.contains('mermaid-container')) {
        return el.querySelector?.<SVGElement>('svg') ?? null;
    }

    const container =
        el.querySelector?.<HTMLElement>('.mermaid-container') ??
        el.closest?.<HTMLElement>('.mermaid-container') ??
        resolveMermaidBlock(el)?.querySelector?.<HTMLElement>(
            '.mermaid-container',
        );

    return container?.querySelector?.<SVGElement>('svg') ?? null;
}

export function getMermaidViewport(target: HTMLElement): MermaidViewport {
    const block = resolveMermaidBlock(target) ?? target;
    return blockViewports.get(block) ?? { ...DEFAULT_MERMAID_VIEWPORT };
}

export function isMermaidPanActive(target: HTMLElement): boolean {
    const block = resolveMermaidBlock(target) ?? target;
    return blockPanActive.get(block) ?? block.dataset?.panActive === 'true';
}

export function applyMermaidTransform(
    target: HTMLElement | SVGElement,
    viewport: MermaidViewport,
): void {
    if (typeof window === 'undefined' || !target) {
        return;
    }

    const svg = resolveMermaidSvg(target);
    if (svg) {
        svg.style.transformOrigin = 'center center';
        svg.style.transform = formatMermaidTransform(viewport);
    }

    if (typeof HTMLElement !== 'undefined' && target instanceof HTMLElement) {
        const block = resolveMermaidBlock(target) ?? target;
        blockViewports.set(block, { ...viewport });

        const container = block.classList?.contains('mermaid-container')
            ? block
            : block.querySelector?.<HTMLElement>('.mermaid-container');
        if (container) {
            container.style.overflow = 'hidden';
        }
    }
}

export function setMermaidPanActive(
    target: HTMLElement,
    active: boolean,
): void {
    if (!target) {
        return;
    }
    const block = resolveMermaidBlock(target) ?? target;
    blockPanActive.set(block, active);

    if (active) {
        block.dataset.panActive = 'true';
    } else {
        delete block.dataset.panActive;
    }

    const panBtn = block.querySelector?.<HTMLButtonElement>(
        'button[data-mermaid-pan]',
    );
    if (panBtn) {
        if (active) {
            panBtn.dataset.panActive = 'true';
            panBtn.setAttribute('aria-pressed', 'true');
        } else {
            delete panBtn.dataset.panActive;
            panBtn.setAttribute('aria-pressed', 'false');
        }
    }

    const container = block.classList?.contains('mermaid-container')
        ? block
        : block.querySelector?.<HTMLElement>('.mermaid-container');
    if (container) {
        if (container.style) {
            container.style.touchAction = active ? 'none' : '';
        }
        if (active) {
            container.dataset.panActive = 'true';
        } else {
            delete container.dataset.panActive;
            container.classList.remove('is-dragging');
        }
    }
}

export function toggleMermaidPan(
    target: HTMLElement,
    force?: boolean,
): boolean {
    const current = isMermaidPanActive(target);
    const nextState = force !== undefined ? force : !current;
    setMermaidPanActive(target, nextState);
    return nextState;
}

export function resetMermaidViewport(target: HTMLElement): MermaidViewport {
    const defaultVp = { ...DEFAULT_MERMAID_VIEWPORT };
    applyMermaidTransform(target, defaultVp);
    setMermaidPanActive(target, false);
    return defaultVp;
}

export function zoomMermaidBlock(
    target: HTMLElement,
    delta: number,
): MermaidViewport {
    const current = getMermaidViewport(target);
    const updated: MermaidViewport = {
        ...current,
        zoom: stepMermaidZoom(current.zoom, delta),
    };
    applyMermaidTransform(target, updated);
    return updated;
}

export function setupMermaidPanZoom(target: HTMLElement): () => void {
    if (typeof window === 'undefined' || !target) {
        return () => {};
    }

    const block = resolveMermaidBlock(target) ?? target;
    const container = block.classList?.contains('mermaid-container')
        ? block
        : block.querySelector?.<HTMLElement>('.mermaid-container');

    if (!container) {
        return () => {};
    }

    // Clean up previous listeners if attached
    const existingCleanup = blockCleanupFns.get(container);
    if (existingCleanup) {
        existingCleanup();
    }

    // Preserve or initialize viewport on the newly rendered SVG
    const currentVp = blockViewports.get(block) ?? {
        ...DEFAULT_MERMAID_VIEWPORT,
    };
    applyMermaidTransform(block, currentVp);

    // Preserve active pan state if block was already in pan mode
    if (blockPanActive.get(block) || block.dataset.panActive === 'true') {
        setMermaidPanActive(block, true);
    }

    const svg = container.querySelector<SVGElement>('svg');
    if (svg) {
        svg.style.transition = 'transform 0.15s ease-out';
    }

    let isDragging = false;
    let startX = 0;
    let startY = 0;
    let startVp: MermaidViewport = { ...currentVp };

    const onPointerDown = (event: PointerEvent) => {
        if (!isMermaidPanActive(block)) {
            return;
        }
        if (event.button !== 0) {
            return;
        }
        event.preventDefault();
        isDragging = true;
        startX = event.clientX;
        startY = event.clientY;
        startVp = getMermaidViewport(block);

        container.classList.add('is-dragging');
        try {
            container.setPointerCapture?.(event.pointerId);
        } catch {
            // ignore
        }

        const currentSvg = container.querySelector<SVGElement>('svg');
        if (currentSvg) {
            currentSvg.style.transition = 'none';
        }
    };

    const onPointerMove = (event: PointerEvent) => {
        if (!isDragging) {
            return;
        }
        const dx = event.clientX - startX;
        const dy = event.clientY - startY;
        const updated: MermaidViewport = {
            ...startVp,
            x: startVp.x + dx,
            y: startVp.y + dy,
        };
        applyMermaidTransform(block, updated);
        const currentSvg = container.querySelector<SVGElement>('svg');
        if (currentSvg) {
            currentSvg.style.transition = 'none';
        }
    };

    const onPointerUp = (event: PointerEvent) => {
        if (isDragging) {
            isDragging = false;
            container.classList.remove('is-dragging');
            try {
                container.releasePointerCapture?.(event.pointerId);
            } catch {
                // ignore
            }
            const currentSvg = container.querySelector<SVGElement>('svg');
            if (currentSvg) {
                currentSvg.style.transition = 'transform 0.15s ease-out';
            }
        }
    };

    const onWheel = (event: WheelEvent) => {
        if (event.ctrlKey || event.metaKey) {
            event.preventDefault();
            const delta =
                event.deltaY < 0 ? MERMAID_ZOOM_STEP : -MERMAID_ZOOM_STEP;
            zoomMermaidBlock(block, delta);
        }
    };

    container.addEventListener('pointerdown', onPointerDown);
    container.addEventListener('pointermove', onPointerMove);
    container.addEventListener('pointerup', onPointerUp);
    container.addEventListener('pointercancel', onPointerUp);
    container.addEventListener('wheel', onWheel, { passive: false });

    const cleanup = () => {
        container.removeEventListener('pointerdown', onPointerDown);
        container.removeEventListener('pointermove', onPointerMove);
        container.removeEventListener('pointerup', onPointerUp);
        container.removeEventListener('pointercancel', onPointerUp);
        container.removeEventListener('wheel', onWheel);
        blockCleanupFns.delete(container);
    };

    blockCleanupFns.set(container, cleanup);
    return cleanup;
}
