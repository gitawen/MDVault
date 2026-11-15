import { describe, expect, it } from 'vite-plus/test';
import {
    clearSvgCache,
    getMermaidConfig,
    getMermaidTheme,
    getSvgCacheKey,
    svgCache,
} from '../../../resources/js/lib/editor/mermaidRenderer';
import {
    DEFAULT_MERMAID_CODE,
    MERMAID_TEMPLATES,
    getMermaidTemplate,
} from '../../../resources/js/lib/editor/mermaidTemplates';

describe('Mermaid Templates', () => {
    it('provides valid presets for all 9 diagram types (FR-05)', () => {
        const expectedIds = [
            'flowchart',
            'sequence',
            'state',
            'class',
            'er',
            'gantt',
            'pie',
            'mindmap',
            'gitGraph',
        ];

        expect(MERMAID_TEMPLATES.length).toBe(9);

        for (const id of expectedIds) {
            const template = getMermaidTemplate(id);
            expect(template).toBeDefined();
            expect(template?.id).toBe(id);
            expect(template?.name.length).toBeGreaterThan(0);
            expect(template?.description.length).toBeGreaterThan(0);
            expect(template?.code.trim().length).toBeGreaterThan(0);
        }
    });

    it('returns undefined for an unknown template id', () => {
        expect(getMermaidTemplate('nonexistent')).toBeUndefined();
    });

    it('defines a valid DEFAULT_MERMAID_CODE matching flowchart template', () => {
        expect(DEFAULT_MERMAID_CODE).toBe(MERMAID_TEMPLATES[0].code);
        expect(DEFAULT_MERMAID_CODE).toContain('graph TD');
    });
});

describe('Mermaid Renderer Configuration & Cache', () => {
    it('getMermaidTheme maps false to default and true to dark (FR-07)', () => {
        expect(getMermaidTheme(false)).toBe('default');
        expect(getMermaidTheme(true)).toBe('dark');
    });

    it('getMermaidConfig specifies securityLevel strict and disables startOnLoad (FR-09)', () => {
        const lightConfig = getMermaidConfig(false);
        expect(lightConfig.securityLevel).toBe('strict');
        expect(lightConfig.startOnLoad).toBe(false);
        expect(lightConfig.theme).toBe('default');

        const darkConfig = getMermaidConfig(true);
        expect(darkConfig.securityLevel).toBe('strict');
        expect(darkConfig.startOnLoad).toBe(false);
        expect(darkConfig.theme).toBe('dark');
    });

    it('svgCache generates stable cache keys and caches diagram results (FR-10)', () => {
        clearSvgCache();
        expect(svgCache.size).toBe(0);

        const key1 = getSvgCacheKey('graph TD\nA-->B', false);
        const key2 = getSvgCacheKey('  graph TD\nA-->B  ', false);
        const keyDark = getSvgCacheKey('graph TD\nA-->B', true);

        expect(key1).toBe('default:graph TD\nA-->B');
        expect(key2).toBe('default:graph TD\nA-->B');
        expect(keyDark).toBe('dark:graph TD\nA-->B');

        svgCache.set(key1, '<svg>mock-diagram</svg>');
        expect(svgCache.has(key1)).toBe(true);
        expect(svgCache.get(key1)).toBe('<svg>mock-diagram</svg>');

        clearSvgCache();
        expect(svgCache.size).toBe(0);
    });
});
