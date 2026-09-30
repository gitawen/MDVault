export type SafetyCheckedVisit = {
    url: URL;
    method: string;
    only: string[];
};

/**
 * Whether an Inertia visit is a tree-only partial reload that
 * `useUnsavedChangesGuard` can let through untouched (FR-10): a same-URL GET
 * whose `only` list is non-empty and does not include `note`. Any visit
 * touching `note`, or a full/POST visit, is not editor-safe and still goes
 * through the guard.
 */
export function isEditorSafeVisit(
    visit: SafetyCheckedVisit,
    currentUrl: string,
): boolean {
    if (visit.method !== 'get') {
        return false;
    }

    if (visit.only.length === 0 || visit.only.includes('note')) {
        return false;
    }

    return visit.url.pathname === new URL(currentUrl).pathname;
}
