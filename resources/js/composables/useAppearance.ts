import { router } from '@inertiajs/vue3';
import type { ComputedRef, Ref } from 'vue';
import { computed, onMounted, ref } from 'vue';
import { update } from '@/actions/App/Http/Controllers/Settings/AppearanceController';
import type { Appearance, ResolvedAppearance } from '@/types';

export type { Appearance, ResolvedAppearance };

export type UseAppearanceReturn = {
    appearance: Ref<Appearance>;
    resolvedAppearance: ComputedRef<ResolvedAppearance>;
    updateAppearance: (value: Appearance) => void;
};

export function updateTheme(value: Appearance): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (value === 'system') {
        const mediaQueryList = window.matchMedia(
            '(prefers-color-scheme: dark)',
        );
        const systemTheme = mediaQueryList.matches ? 'dark' : 'light';

        document.documentElement.classList.toggle(
            'dark',
            systemTheme === 'dark',
        );
    } else {
        document.documentElement.classList.toggle('dark', value === 'dark');
    }
}

const mediaQuery = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

function readServerAppearance(): Appearance {
    if (typeof document === 'undefined') {
        return 'system';
    }

    const value = document.documentElement.dataset.appearance;

    return value === 'light' || value === 'dark' || value === 'system'
        ? value
        : 'system';
}

const handleSystemThemeChange = () => {
    updateTheme(appearance.value);
};

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    updateTheme(readServerAppearance());

    // Set up system theme change listener...
    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

const appearance = ref<Appearance>(readServerAppearance());

export function useAppearance(): UseAppearanceReturn {
    onMounted(() => {
        appearance.value = readServerAppearance();
    });

    const resolvedAppearance = computed<ResolvedAppearance>(() => {
        if (appearance.value === 'system') {
            return prefersDark() ? 'dark' : 'light';
        }

        return appearance.value;
    });

    function updateAppearance(value: Appearance) {
        const previous = appearance.value;

        appearance.value = value;
        document.documentElement.dataset.appearance = value;
        updateTheme(value);

        router.patch(
            update.url(),
            { theme: value },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => {
                    appearance.value = previous;
                    document.documentElement.dataset.appearance = previous;
                    updateTheme(previous);
                },
            },
        );
    }

    return {
        appearance,
        resolvedAppearance,
        updateAppearance,
    };
}
