import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { copyToClipboard } from '../../../resources/js/lib/editor/clipboard';
import {
    CHECK_ICON_SVG,
    COPY_ICON_SVG,
    triggerCopyFeedback,
} from '../../../resources/js/lib/editor/copyButton';

function createMockButton(): HTMLButtonElement {
    const attributes = new Map<string, string>();
    const classList = new Set<string>();

    return {
        innerHTML: '',
        setAttribute(name: string, value: string) {
            attributes.set(name, value);
        },
        getAttribute(name: string) {
            return attributes.get(name) ?? null;
        },
        classList: {
            add(cls: string) {
                classList.add(cls);
            },
            remove(cls: string) {
                classList.delete(cls);
            },
            contains(cls: string) {
                return classList.has(cls);
            },
        },
    } as unknown as HTMLButtonElement;
}

describe('copyToClipboard', () => {
    const originalDocument = (globalThis as { document?: unknown }).document;

    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        if (originalDocument !== undefined) {
            (globalThis as { document?: unknown }).document = originalDocument;
        } else {
            delete (globalThis as { document?: unknown }).document;
        }
    });

    it('copies text via navigator.clipboard.writeText when available', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', {
            clipboard: { writeText },
        });

        const result = await copyToClipboard('const x = 1;');
        expect(result).toBe(true);
        expect(writeText).toHaveBeenCalledWith('const x = 1;');
    });

    it('falls back to document.execCommand when navigator.clipboard fails', async () => {
        const writeText = vi
            .fn()
            .mockRejectedValue(new Error('Permission denied'));
        vi.stubGlobal('navigator', {
            clipboard: { writeText },
        });

        const textarea = {
            value: '',
            setAttribute: vi.fn(),
            style: {} as Record<string, string>,
            select: vi.fn(),
        };
        let execCommandCalled = '';
        (globalThis as { document?: unknown }).document = {
            createElement: vi.fn().mockReturnValue(textarea),
            body: {
                appendChild: vi.fn(),
                removeChild: vi.fn(),
            },
            execCommand: (cmd: string) => {
                execCommandCalled = cmd;
                return true;
            },
        };

        const result = await copyToClipboard('fallback snippet');
        expect(result).toBe(true);
        expect(textarea.value).toBe('fallback snippet');
        expect(execCommandCalled).toBe('copy');
    });

    it('returns false when both navigator.clipboard and fallback fail', async () => {
        vi.stubGlobal('navigator', {
            clipboard: undefined,
        });

        (globalThis as { document?: unknown }).document = {
            createElement: vi.fn().mockReturnValue({
                setAttribute: vi.fn(),
                style: {},
                select: vi.fn(),
            }),
            body: {
                appendChild: vi.fn(),
                removeChild: vi.fn(),
            },
            execCommand: () => false,
        };

        const result = await copyToClipboard('text');
        expect(result).toBe(false);
    });

    it('returns false when document is undefined and clipboard throws', async () => {
        vi.stubGlobal('navigator', {
            clipboard: {
                writeText: vi.fn().mockRejectedValue(new Error('no clipboard')),
            },
        });
        delete (globalThis as { document?: unknown }).document;

        const result = await copyToClipboard('text');
        expect(result).toBe(false);
    });
});

describe('triggerCopyFeedback', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('updates button to copied state and restores default state after duration', async () => {
        vi.stubGlobal('navigator', {
            clipboard: {
                writeText: vi.fn().mockResolvedValue(undefined),
            },
        });

        const button = createMockButton();
        button.innerHTML = COPY_ICON_SVG;
        button.setAttribute('aria-label', 'Copy code');

        const promise = triggerCopyFeedback(button, 'my code', 1500);
        const result = await promise;

        expect(result).toBe(true);
        expect(button.innerHTML).toBe(CHECK_ICON_SVG);
        expect(button.getAttribute('aria-label')).toBe('Copied!');
        expect(button.getAttribute('title')).toBe('Copied!');
        expect(button.classList.contains('copied')).toBe(true);

        vi.advanceTimersByTime(1499);
        expect(button.innerHTML).toBe(CHECK_ICON_SVG);

        vi.advanceTimersByTime(1);
        expect(button.innerHTML).toBe(COPY_ICON_SVG);
        expect(button.getAttribute('aria-label')).toBe('Copy code');
        expect(button.getAttribute('title')).toBe('Copy code');
        expect(button.classList.contains('copied')).toBe(false);
    });

    it('re-arms timeout if clicked again before timeout finishes', async () => {
        vi.stubGlobal('navigator', {
            clipboard: {
                writeText: vi.fn().mockResolvedValue(undefined),
            },
        });

        const button = createMockButton();
        await triggerCopyFeedback(button, 'first copy', 1000);

        vi.advanceTimersByTime(500);
        expect(button.classList.contains('copied')).toBe(true);

        await triggerCopyFeedback(button, 'second copy', 1000);

        vi.advanceTimersByTime(500);
        // At 1000ms from start (500ms into second click), should still be copied!
        expect(button.classList.contains('copied')).toBe(true);

        vi.advanceTimersByTime(500);
        // Now at 1000ms after second click, should be reverted
        expect(button.classList.contains('copied')).toBe(false);
    });

    it('does not transition button if copying fails', async () => {
        vi.stubGlobal('navigator', {
            clipboard: {
                writeText: vi.fn().mockRejectedValue(new Error('fail')),
            },
        });
        delete (globalThis as { document?: unknown }).document;

        const button = createMockButton();
        button.innerHTML = COPY_ICON_SVG;
        button.setAttribute('aria-label', 'Copy code');

        const result = await triggerCopyFeedback(button, 'code');
        expect(result).toBe(false);
        expect(button.innerHTML).toBe(COPY_ICON_SVG);
        expect(button.getAttribute('aria-label')).toBe('Copy code');
        expect(button.classList.contains('copied')).toBe(false);
    });
});
