/// <reference types="node" />
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vite-plus/test';

const ROOT = join(__dirname, '../../..');

/**
 * The one part of `retire-tiptap-rich-mode` that *is* automatically
 * verifiable: no `@tiptap/*` (or the two other now-unused packages)
 * package.json entry, and no stray `@tiptap`/`isTipTap`/`useEditorTick`/
 * `tiptap-` string anywhere under `resources/js` or `resources/css/app.css`.
 * Guards against a future paste re-introducing the dependency.
 */
describe('no TipTap dependency remains', () => {
    it('package.json has no @tiptap/*, codemirror, or @codemirror/theme-one-dark entry', () => {
        const pkg = JSON.parse(
            readFileSync(join(ROOT, 'package.json'), 'utf-8'),
        ) as {
            dependencies?: Record<string, string>;
            devDependencies?: Record<string, string>;
            optionalDependencies?: Record<string, string>;
        };

        const allDeps = {
            ...pkg.dependencies,
            ...pkg.devDependencies,
            ...pkg.optionalDependencies,
        };

        for (const name of Object.keys(allDeps)) {
            expect(name.startsWith('@tiptap/')).toBe(false);
            expect(name).not.toBe('codemirror');
            expect(name).not.toBe('@codemirror/theme-one-dark');
        }
    });

    const FORBIDDEN_PATTERNS = [
        /@tiptap/,
        /isTipTap/,
        /useEditorTick/,
        /tiptap-/,
    ];
    const SCAN_EXTENSIONS = new Set(['.ts', '.vue']);

    function walk(dir: string, files: string[] = []): string[] {
        for (const entry of readdirSync(dir)) {
            if (entry === 'node_modules') {
                continue;
            }

            const fullPath = join(dir, entry);
            const stats = statSync(fullPath);

            if (stats.isDirectory()) {
                walk(fullPath, files);
            } else if (
                SCAN_EXTENSIONS.has(entry.slice(entry.lastIndexOf('.')))
            ) {
                files.push(fullPath);
            }
        }

        return files;
    }

    it('resources/js contains no @tiptap, isTipTap, useEditorTick or tiptap- string', () => {
        const files = walk(join(ROOT, 'resources/js'));
        const offenders: string[] = [];

        for (const file of files) {
            const content = readFileSync(file, 'utf-8');
            if (FORBIDDEN_PATTERNS.some((pattern) => pattern.test(content))) {
                offenders.push(file);
            }
        }

        expect(offenders).toEqual([]);
    });

    it('resources/css/app.css contains no tiptap- string', () => {
        const content = readFileSync(
            join(ROOT, 'resources/css/app.css'),
            'utf-8',
        );

        expect(/tiptap-/.test(content)).toBe(false);
    });
});
