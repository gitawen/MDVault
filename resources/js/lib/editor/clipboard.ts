/**
 * Copies the given text to the clipboard.
 * Uses navigator.clipboard.writeText with a fallback to document.execCommand('copy')
 * for insecure or restricted contexts.
 */
export async function copyToClipboard(text: string): Promise<boolean> {
    if (
        typeof navigator !== 'undefined' &&
        navigator.clipboard &&
        typeof navigator.clipboard.writeText === 'function'
    ) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch {
            // Fall back to execCommand if clipboard API fails
        }
    }

    if (typeof document === 'undefined') {
        return false;
    }

    try {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.top = '-9999px';
        textarea.style.left = '-9999px';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        const success = document.execCommand('copy');
        document.body.removeChild(textarea);
        return Boolean(success);
    } catch {
        return false;
    }
}
