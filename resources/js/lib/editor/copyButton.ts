import { copyToClipboard } from './clipboard';

export const COPY_ICON_SVG =
    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-copy"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>';

export const CHECK_ICON_SVG =
    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-check"><path d="M20 6 9 17l-5-5"/></svg>';

type OriginalButtonState = {
    html: string;
    ariaLabel: string | null;
    title: string | null;
};

const activeTimeouts = new WeakMap<
    HTMLButtonElement,
    ReturnType<typeof setTimeout>
>();

const originalButtonStates = new WeakMap<
    HTMLButtonElement,
    OriginalButtonState
>();

export type CopyFeedbackOptions = {
    durationMs?: number;
    restoreHtml?: string;
    restoreAriaLabel?: string;
    restoreTitle?: string;
};

/**
 * Copies the provided code text to the clipboard and triggers temporary
 * visual feedback (checkmark icon, 'Copied!' label and class) on the button.
 * Restores the button's original innerHTML, aria-label, and title upon expiration.
 */
export async function triggerCopyFeedback(
    button: HTMLButtonElement,
    code: string,
    durationMsOrOptions: number | CopyFeedbackOptions = 2000,
): Promise<boolean> {
    const success = await copyToClipboard(code);
    if (!success) {
        return false;
    }

    const options: CopyFeedbackOptions =
        typeof durationMsOrOptions === 'number'
            ? { durationMs: durationMsOrOptions }
            : durationMsOrOptions;

    const durationMs = options.durationMs ?? 2000;

    const existingTimeout = activeTimeouts.get(button);
    if (existingTimeout) {
        clearTimeout(existingTimeout);
    }

    if (!originalButtonStates.has(button)) {
        originalButtonStates.set(button, {
            html: button.innerHTML,
            ariaLabel: button.getAttribute('aria-label'),
            title: button.getAttribute('title'),
        });
    }

    const original = originalButtonStates.get(button)!;

    button.innerHTML = CHECK_ICON_SVG;
    button.setAttribute('aria-label', 'Copied!');
    button.setAttribute('title', 'Copied!');
    button.classList.add('copied');

    const timeout = setTimeout(() => {
        const restoreHtml =
            options.restoreHtml ?? original.html ?? COPY_ICON_SVG;
        button.innerHTML = restoreHtml;

        const restoreAriaLabel =
            options.restoreAriaLabel ?? original.ariaLabel ?? 'Copy code';
        button.setAttribute('aria-label', restoreAriaLabel);

        const restoreTitle =
            options.restoreTitle ??
            original.title ??
            original.ariaLabel ??
            'Copy code';
        button.setAttribute('title', restoreTitle);

        button.classList.remove('copied');
        activeTimeouts.delete(button);
        originalButtonStates.delete(button);
    }, durationMs);

    activeTimeouts.set(button, timeout);
    return true;
}
