import { copyToClipboard } from './clipboard';

export const COPY_ICON_SVG =
    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-copy"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>';

export const CHECK_ICON_SVG =
    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-check"><path d="M20 6 9 17l-5-5"/></svg>';

const activeTimeouts = new WeakMap<
    HTMLButtonElement,
    ReturnType<typeof setTimeout>
>();

/**
 * Copies the provided code text to the clipboard and triggers temporary
 * visual feedback (checkmark icon, 'Copied!' label and class) on the button.
 */
export async function triggerCopyFeedback(
    button: HTMLButtonElement,
    code: string,
    durationMs = 2000,
): Promise<boolean> {
    const success = await copyToClipboard(code);
    if (!success) {
        return false;
    }

    const existingTimeout = activeTimeouts.get(button);
    if (existingTimeout) {
        clearTimeout(existingTimeout);
    }

    button.innerHTML = CHECK_ICON_SVG;
    button.setAttribute('aria-label', 'Copied!');
    button.setAttribute('title', 'Copied!');
    button.classList.add('copied');

    const timeout = setTimeout(() => {
        button.innerHTML = COPY_ICON_SVG;
        button.setAttribute('aria-label', 'Copy code');
        button.setAttribute('title', 'Copy code');
        button.classList.remove('copied');
        activeTimeouts.delete(button);
    }, durationMs);

    activeTimeouts.set(button, timeout);
    return true;
}
