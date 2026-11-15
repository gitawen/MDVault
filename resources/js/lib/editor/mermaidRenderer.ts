import { escapeHtml } from './markdownPreview';
import { setupMermaidPanZoom } from './mermaidPanZoom';

export type MermaidTheme = 'dark' | 'default';

export function getMermaidTheme(isDark: boolean): MermaidTheme {
    return isDark ? 'dark' : 'default';
}

export function getMermaidConfig(isDark: boolean) {
    return {
        startOnLoad: false,
        securityLevel: 'strict' as const,
        theme: getMermaidTheme(isDark),
        fontFamily: 'inherit',
        suppressErrorRendering: true,
    };
}

let mermaidInstance: any = null;
let lastConfiguredTheme: MermaidTheme | null = null;
let idCounter = 0;

export async function loadMermaid(isDark: boolean = false): Promise<any> {
    if (!mermaidInstance) {
        const mod = await import('mermaid');
        mermaidInstance = mod.default ?? mod;
    }

    const theme = getMermaidTheme(isDark);
    if (lastConfiguredTheme !== theme) {
        mermaidInstance.initialize(getMermaidConfig(isDark));
        lastConfiguredTheme = theme;
    }

    return mermaidInstance;
}

export const svgCache = new Map<string, string>();

export function clearSvgCache(): void {
    svgCache.clear();
}

export function getSvgCacheKey(source: string, isDark: boolean): string {
    return `${getMermaidTheme(isDark)}:${source.trim()}`;
}

export async function renderMermaidDiagram(
    source: string,
    isDark: boolean,
    customId?: string,
): Promise<{ svg: string }> {
    const trimmed = source.trim();
    if (!trimmed) {
        return { svg: '' };
    }

    const cacheKey = getSvgCacheKey(trimmed, isDark);
    const cached = svgCache.get(cacheKey);
    if (cached !== undefined) {
        return { svg: cached };
    }

    const mermaid = await loadMermaid(isDark);
    const id = customId ?? `mermaid-svg-${Date.now()}-${++idCounter}`;

    try {
        const result = await mermaid.render(id, trimmed);
        const svg = typeof result === 'string' ? result : result.svg;
        svgCache.set(cacheKey, svg);
        return { svg };
    } catch (err: any) {
        // Clean up any temp element inserted by Mermaid in document.body
        if (typeof document !== 'undefined') {
            const el1 = document.getElementById(id);
            if (el1 && el1.parentNode) {
                el1.remove();
            }
            const el2 = document.getElementById(`d${id}`);
            if (el2 && el2.parentNode) {
                el2.remove();
            }
        }
        const message =
            err?.message ||
            (typeof err === 'string' ? err : 'Invalid Mermaid syntax');
        throw new Error(message);
    }
}

export async function renderMermaidInContainer(
    container: HTMLElement,
    isDark: boolean,
): Promise<void> {
    if (typeof window === 'undefined' || !container) {
        return;
    }

    const blocks = container.querySelectorAll<HTMLElement>('.mermaid-block');
    if (blocks.length === 0) {
        return;
    }

    await loadMermaid(isDark);
    const targetTheme = isDark ? 'dark' : 'light';

    for (const element of Array.from(blocks)) {
        if (
            element.dataset.renderedTheme === targetTheme &&
            element.querySelector('.mermaid-container svg')
        ) {
            continue;
        }

        const source =
            element.dataset.mermaidSource ??
            element.querySelector('.mermaid-raw code')?.textContent ??
            '';

        if (!source.trim()) {
            const loadingEl = element.querySelector('.mermaid-loading');
            if (loadingEl) {
                loadingEl.remove();
            }
            continue;
        }

        try {
            const { svg } = await renderMermaidDiagram(source, isDark);
            const mermaidContainer =
                element.querySelector<HTMLElement>('.mermaid-container');
            if (mermaidContainer) {
                mermaidContainer.innerHTML = svg;
                setupMermaidPanZoom(element);
            }

            const loadingEl = element.querySelector('.mermaid-loading');
            if (loadingEl) {
                loadingEl.remove();
            }

            const errorBox = element.querySelector('.mermaid-error-box');
            if (errorBox) {
                errorBox.remove();
            }

            element.dataset.renderedTheme = targetTheme;
        } catch (err: any) {
            const errorMessage =
                err instanceof Error ? err.message : String(err);
            const mermaidContainer =
                element.querySelector<HTMLElement>('.mermaid-container');
            if (mermaidContainer) {
                mermaidContainer.innerHTML = '';
            }

            const loadingEl = element.querySelector('.mermaid-loading');
            if (loadingEl) {
                loadingEl.remove();
            }

            let errorBox =
                element.querySelector<HTMLElement>('.mermaid-error-box');
            if (!errorBox) {
                errorBox = document.createElement('div');
                errorBox.className =
                    'mermaid-error-box rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive my-2';
                element.appendChild(errorBox);
            }

            errorBox.innerHTML = `
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-start gap-2">
                        <svg class="size-4 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <div>
                            <p class="font-medium text-destructive">Diagram syntax error</p>
                            <p class="text-xs text-destructive/80 mt-0.5 font-mono whitespace-pre-wrap">${escapeHtml(errorMessage)}</p>
                        </div>
                    </div>
                    <button type="button" class="mermaid-toggle-source-btn text-xs font-medium text-destructive hover:underline cursor-pointer shrink-0" data-mermaid-toggle-source>
                        Toggle source
                    </button>
                </div>
            `;
            element.dataset.renderedTheme = targetTheme;
        }
    }
}

export async function copyMermaidSvg(svg: string): Promise<boolean> {
    if (typeof navigator === 'undefined' || !navigator.clipboard) {
        return false;
    }
    try {
        await navigator.clipboard.writeText(svg);
        return true;
    } catch {
        return false;
    }
}
