import { describe, expect, it } from 'vite-plus/test';
import {
    DEFAULT_MERMAID_VIEWPORT,
    MERMAID_PAN_STEP,
    MERMAID_ZOOM_MAX,
    MERMAID_ZOOM_MIN,
    MERMAID_ZOOM_STEP,
    clampMermaidZoom,
    formatMermaidTransform,
    formatMermaidZoomPercent,
    panMermaidBlock,
    resolveMermaidSvg,
    setMermaidPanActive,
    stepMermaidPan,
    stepMermaidZoom,
    zoomMermaidBlock,
    type MermaidViewport,
} from '../../../resources/js/lib/editor/mermaidPanZoom';

describe('Mermaid Pan & Zoom Engine', () => {
    describe('DEFAULT_MERMAID_VIEWPORT', () => {
        it('has default zoom 1 and pan offset (0, 0)', () => {
            expect(DEFAULT_MERMAID_VIEWPORT.zoom).toBe(1);
            expect(DEFAULT_MERMAID_VIEWPORT.x).toBe(0);
            expect(DEFAULT_MERMAID_VIEWPORT.y).toBe(0);
        });

        it('is an immutable frozen object', () => {
            expect(Object.isFrozen(DEFAULT_MERMAID_VIEWPORT)).toBe(true);
        });
    });

    describe('clampMermaidZoom', () => {
        it('preserves valid zoom within [0.25, 4.0]', () => {
            expect(clampMermaidZoom(1)).toBe(1);
            expect(clampMermaidZoom(1.5)).toBe(1.5);
            expect(clampMermaidZoom(0.5)).toBe(0.5);
            expect(clampMermaidZoom(MERMAID_ZOOM_MIN)).toBe(0.25);
            expect(clampMermaidZoom(MERMAID_ZOOM_MAX)).toBe(4.0);
        });

        it('clamps values below minimum to MERMAID_ZOOM_MIN (0.25)', () => {
            expect(clampMermaidZoom(0.1)).toBe(0.25);
            expect(clampMermaidZoom(0)).toBe(0.25);
            expect(clampMermaidZoom(-2)).toBe(0.25);
        });

        it('clamps values above maximum to MERMAID_ZOOM_MAX (4.0)', () => {
            expect(clampMermaidZoom(4.1)).toBe(4.0);
            expect(clampMermaidZoom(10)).toBe(4.0);
        });

        it('handles NaN and non-finite numbers safely', () => {
            expect(clampMermaidZoom(NaN)).toBe(1);
            expect(clampMermaidZoom(Infinity)).toBe(1);
            expect(clampMermaidZoom(-Infinity)).toBe(1);
        });

        it('rounds floating point precision artifacts to 2 decimal places', () => {
            expect(clampMermaidZoom(0.8000000000000004)).toBe(0.8);
            expect(clampMermaidZoom(1.2000000000000002)).toBe(1.2);
            expect(clampMermaidZoom(0.33333333)).toBe(0.33);
        });

        it('respects custom min/max bounds when provided', () => {
            expect(clampMermaidZoom(0.5, 1.0, 3.0)).toBe(1.0);
            expect(clampMermaidZoom(3.5, 1.0, 3.0)).toBe(3.0);
            expect(clampMermaidZoom(2.0, 1.0, 3.0)).toBe(2.0);
        });
    });

    describe('stepMermaidZoom', () => {
        it('increases zoom by delta correctly', () => {
            expect(stepMermaidZoom(1, MERMAID_ZOOM_STEP)).toBe(1.2);
            expect(stepMermaidZoom(1.2, MERMAID_ZOOM_STEP)).toBe(1.4);
        });

        it('decreases zoom by delta correctly', () => {
            expect(stepMermaidZoom(1, -MERMAID_ZOOM_STEP)).toBe(0.8);
            expect(stepMermaidZoom(0.8, -MERMAID_ZOOM_STEP)).toBe(0.6);
        });

        it('clamps stepping at maximum bounds', () => {
            expect(stepMermaidZoom(3.9, 0.2)).toBe(4.0);
            expect(stepMermaidZoom(4.0, 0.2)).toBe(4.0);
        });

        it('clamps stepping at minimum bounds', () => {
            expect(stepMermaidZoom(0.35, -0.2)).toBe(0.25);
            expect(stepMermaidZoom(0.25, -0.2)).toBe(0.25);
        });

        it('handles non-finite initial zoom safely', () => {
            expect(stepMermaidZoom(NaN, 0.2)).toBe(1.2);
        });
    });

    describe('formatMermaidTransform', () => {
        it('formats default viewport as translate(0px, 0px) scale(1)', () => {
            expect(formatMermaidTransform(DEFAULT_MERMAID_VIEWPORT)).toBe(
                'translate(0px, 0px) scale(1)',
            );
        });

        it('formats custom pan offsets and zoom scale', () => {
            const vp: MermaidViewport = { x: 50, y: -25, zoom: 1.5 };
            expect(formatMermaidTransform(vp)).toBe(
                'translate(50px, -25px) scale(1.5)',
            );
        });

        it('clamps zoom when formatting if outside valid range', () => {
            expect(formatMermaidTransform({ x: 0, y: 0, zoom: 10 })).toBe(
                'translate(0px, 0px) scale(4)',
            );
            expect(formatMermaidTransform({ x: 0, y: 0, zoom: 0.1 })).toBe(
                'translate(0px, 0px) scale(0.25)',
            );
        });

        it('handles missing or partial viewport fields safely', () => {
            expect(formatMermaidTransform({})).toBe(
                'translate(0px, 0px) scale(1)',
            );
            expect(formatMermaidTransform({ zoom: 2 })).toBe(
                'translate(0px, 0px) scale(2)',
            );
            expect(formatMermaidTransform({ x: 30 })).toBe(
                'translate(30px, 0px) scale(1)',
            );
        });

        it('rounds pan offsets to 1 decimal place', () => {
            expect(
                formatMermaidTransform({ x: 12.345, y: -45.678, zoom: 1 }),
            ).toBe('translate(12.3px, -45.7px) scale(1)');
        });
    });

    describe('resolveMermaidSvg', () => {
        it('resolves .mermaid-container svg and NOT toolbar svg when toolbar comes first in DOM', () => {
            const toolbarSvg = {
                tagName: 'svg',
                id: 'toolbar-pan-icon',
            } as unknown as SVGElement;

            const diagramSvg = {
                tagName: 'svg',
                id: 'mermaid-diagram-svg',
            } as unknown as SVGElement;

            const toolbarEl = {
                classList: {
                    contains: (cls: string) => cls === 'mermaid-toolbar',
                },
                querySelector: (selector: string) => {
                    if (selector === 'svg') return toolbarSvg;
                    return null;
                },
            };

            const containerEl = {
                classList: {
                    contains: (cls: string) => cls === 'mermaid-container',
                },
                querySelector: (selector: string) => {
                    if (selector === 'svg') return diagramSvg;
                    return null;
                },
                closest: () => null,
            };

            const blockEl = {
                classList: {
                    contains: (cls: string) => cls === 'mermaid-block',
                },
                querySelector: (selector: string) => {
                    // Simulating DOM order: toolbar is before container, so querying 'svg' on block
                    // directly would naively match the toolbar icon
                    if (selector === 'svg') return toolbarSvg;
                    if (selector === '.mermaid-toolbar') return toolbarEl;
                    if (selector === '.mermaid-container') return containerEl;
                    return null;
                },
                closest: () => null,
            } as unknown as HTMLElement;

            const resolved = resolveMermaidSvg(blockEl);
            expect(resolved).toBe(diagramSvg);
            expect(resolved).not.toBe(toolbarSvg);
        });

        it('returns target directly if target is an svg element', () => {
            const svgEl = { tagName: 'svg' } as unknown as SVGElement;
            expect(resolveMermaidSvg(svgEl)).toBe(svgEl);
        });

        it('resolves svg from .mermaid-container when target is container directly', () => {
            const diagramSvg = { tagName: 'svg' } as unknown as SVGElement;
            const containerEl = {
                classList: {
                    contains: (cls: string) => cls === 'mermaid-container',
                },
                querySelector: (selector: string) => {
                    if (selector === 'svg') return diagramSvg;
                    return null;
                },
            } as unknown as HTMLElement;

            expect(resolveMermaidSvg(containerEl)).toBe(diagramSvg);
        });

        it('resolves .mermaid-container svg when target is an inner button inside .mermaid-block', () => {
            const diagramSvg = { tagName: 'svg' } as unknown as SVGElement;
            const containerEl = {
                classList: {
                    contains: (cls: string) => cls === 'mermaid-container',
                },
                querySelector: (selector: string) =>
                    selector === 'svg' ? diagramSvg : null,
            };
            const blockEl = {
                classList: {
                    contains: (cls: string) => cls === 'mermaid-block',
                },
                querySelector: (selector: string) =>
                    selector === '.mermaid-container' ? containerEl : null,
                closest: () => null,
            } as unknown as HTMLElement;

            const buttonEl = {
                classList: { contains: () => false },
                querySelector: () => null,
                closest: (selector: string) =>
                    selector === '.mermaid-block' ? blockEl : null,
            } as unknown as HTMLElement;

            expect(resolveMermaidSvg(buttonEl)).toBe(diagramSvg);
        });

        it('returns null if target is null or undefined or has no container/svg', () => {
            expect(resolveMermaidSvg(null as any)).toBeNull();
            expect(resolveMermaidSvg(undefined as any)).toBeNull();

            const emptyEl = {
                classList: { contains: () => false },
                querySelector: () => null,
                closest: () => null,
            } as unknown as HTMLElement;

            expect(resolveMermaidSvg(emptyEl)).toBeNull();
        });
    });

    describe('setMermaidPanActive', () => {
        it('sets touchAction to none when active and empty string when inactive', () => {
            const containerEl = {
                classList: {
                    contains: (cls: string) => cls === 'mermaid-container',
                    remove: () => {},
                },
                dataset: {} as Record<string, string>,
                style: { touchAction: '' },
            };

            const blockEl = {
                classList: {
                    contains: (cls: string) => cls === 'mermaid-block',
                },
                dataset: {} as Record<string, string>,
                querySelector: (selector: string) => {
                    if (selector === '.mermaid-container') return containerEl;
                    return null;
                },
                closest: () => null,
            } as unknown as HTMLElement;

            setMermaidPanActive(blockEl, true);
            expect(containerEl.style.touchAction).toBe('none');
            expect(containerEl.dataset.panActive).toBe('true');

            setMermaidPanActive(blockEl, false);
            expect(containerEl.style.touchAction).toBe('');
            expect(containerEl.dataset.panActive).toBeUndefined();
        });
    });

    describe('MERMAID_PAN_STEP & stepMermaidPan', () => {
        it('exports MERMAID_PAN_STEP as 40', () => {
            expect(MERMAID_PAN_STEP).toBe(40);
        });

        it('applies positive and negative dx/dy offsets while preserving zoom', () => {
            const initial: MermaidViewport = { zoom: 1.4, x: 10, y: -20 };
            expect(stepMermaidPan(initial, 40, 0)).toEqual({
                zoom: 1.4,
                x: 50,
                y: -20,
            });
            expect(stepMermaidPan(initial, -40, 40)).toEqual({
                zoom: 1.4,
                x: -30,
                y: 20,
            });
        });

        it('guards non-finite viewport coordinates and step deltas with 0', () => {
            expect(
                stepMermaidPan(
                    { zoom: NaN, x: Number.POSITIVE_INFINITY, y: NaN },
                    NaN,
                    Number.NEGATIVE_INFINITY,
                ),
            ).toEqual({
                zoom: 1,
                x: 0,
                y: 0,
            });
        });
    });

    describe('formatMermaidZoomPercent', () => {
        it('formats zoom scale as integer percentage and clamps to [25%, 400%]', () => {
            expect(formatMermaidZoomPercent(1)).toBe('100%');
            expect(formatMermaidZoomPercent(1.2)).toBe('120%');
            expect(formatMermaidZoomPercent(0.8)).toBe('80%');
            expect(formatMermaidZoomPercent(0.1)).toBe('25%');
            expect(formatMermaidZoomPercent(10)).toBe('400%');
            expect(formatMermaidZoomPercent(Number.NaN)).toBe('100%');
        });
    });

    describe('panMermaidBlock', () => {
        it('accumulates directional pan steps on a block and updates SVG transform while preserving zoom', () => {
            const svgEl = {
                tagName: 'svg',
                style: {
                    transform: '',
                    transformOrigin: '',
                },
            } as unknown as SVGElement;

            const containerEl = {
                classList: {
                    contains: (cls: string) => cls === 'mermaid-container',
                },
                style: {
                    overflow: '',
                },
                querySelector: (selector: string) =>
                    selector === 'svg' ? svgEl : null,
            };

            const blockEl = {
                classList: {
                    contains: (cls: string) => cls === 'mermaid-block',
                },
                querySelector: (selector: string) =>
                    selector === '.mermaid-container' ? containerEl : null,
                closest: () => null,
            } as unknown as HTMLElement;

            zoomMermaidBlock(blockEl, MERMAID_ZOOM_STEP);

            const vp1 = panMermaidBlock(blockEl, 0, MERMAID_PAN_STEP);
            expect(vp1).toEqual({ zoom: 1.2, x: 0, y: 40 });
            expect(svgEl.style.transform).toBe(
                'translate(0px, 40px) scale(1.2)',
            );

            const vp2 = panMermaidBlock(blockEl, -MERMAID_PAN_STEP, 0);
            expect(vp2).toEqual({ zoom: 1.2, x: -40, y: 40 });
            expect(svgEl.style.transform).toBe(
                'translate(-40px, 40px) scale(1.2)',
            );
        });
    });
});
