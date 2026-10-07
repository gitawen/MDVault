<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    Archive,
    HardDrive,
    Palette,
    PenLine,
    ShieldCheck,
    SlidersHorizontal,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
} from '@/components/ui/select';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/settings/appearance';
import { edit as editBackup } from '@/routes/settings/backup';
import { edit as editEditor } from '@/routes/settings/editor';
import { edit as editGeneral } from '@/routes/settings/general';
import { edit as editSecurity } from '@/routes/settings/security';
import { edit as editStorage } from '@/routes/settings/storage';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'General',
        href: editGeneral(),
        icon: SlidersHorizontal,
    },
    {
        title: 'Storage',
        href: editStorage(),
        icon: HardDrive,
    },
    {
        title: 'Backup',
        href: editBackup(),
        icon: Archive,
    },
    {
        title: 'Editor',
        href: editEditor(),
        icon: PenLine,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: ShieldCheck,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: Palette,
    },
];

const { isCurrentOrParentUrl, currentUrl } = useCurrentUrl();

const activeItem = computed(
    () =>
        sidebarNavItems.find((item) => isCurrentOrParentUrl(item.href)) ??
        sidebarNavItems[0],
);

const activeHref = computed(() => toUrl(activeItem.value.href));

function onSelectMobileTab(value: unknown) {
    if (typeof value === 'string' && value !== activeHref.value) {
        router.visit(value);
    }
}

const navScrollRef = ref<HTMLElement | null>(null);
const canScrollLeft = ref(false);
const canScrollRight = ref(false);

function updateScrollIndicators() {
    const el = navScrollRef.value;
    if (!el) {
        return;
    }

    canScrollLeft.value = el.scrollLeft > 6;
    canScrollRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 6;
}

function scrollToActiveTab(smooth = true) {
    nextTick(() => {
        if (!navScrollRef.value) {
            return;
        }

        const activeEl = navScrollRef.value.querySelector<HTMLElement>(
            '[data-active="true"]',
        );

        if (activeEl) {
            activeEl.scrollIntoView({
                behavior: smooth ? 'smooth' : 'auto',
                inline: 'center',
                block: 'nearest',
            });
        }

        updateScrollIndicators();
    });
}

onMounted(() => {
    scrollToActiveTab(false);
    updateScrollIndicators();
});

watch(currentUrl, () => {
    scrollToActiveTab(true);
});
</script>

<template>
    <div
        class="mx-auto w-full max-w-5xl min-w-0 px-3 py-4 sm:px-6 sm:py-6 md:px-8 md:py-8"
    >
        <!-- Settings Page Header -->
        <header class="mb-6 border-b border-border/50 pb-5 sm:mb-8 sm:pb-6">
            <div class="flex items-center gap-3">
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary shadow-2xs sm:h-10 sm:w-10"
                >
                    <SlidersHorizontal class="h-4.5 w-4.5 sm:h-5 sm:w-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <h1
                        class="truncate text-xl font-semibold tracking-tight sm:text-2xl"
                    >
                        Settings
                    </h1>
                    <p
                        class="line-clamp-2 text-xs text-muted-foreground sm:line-clamp-none sm:text-sm"
                    >
                        Manage your vault preferences, editor typography, and
                        storage configurations.
                    </p>
                </div>
            </div>
        </header>

        <!-- Mobile Navigation (Quick Select Dropdown + Auto-centering Scrollable Pills) -->
        <div class="mb-6 w-full min-w-0 space-y-3 lg:hidden">
            <!-- Mobile Section Dropdown Menu (Direct Jump) -->
            <div class="sm:hidden">
                <Select
                    :model-value="activeHref"
                    @update:model-value="onSelectMobileTab"
                >
                    <SelectTrigger
                        class="h-10 w-full border-border/70 bg-card text-xs font-medium"
                    >
                        <div class="flex items-center gap-2 truncate">
                            <component
                                :is="activeItem.icon"
                                class="h-3.5 w-3.5 shrink-0 text-primary"
                            />
                            <span class="truncate"
                                >{{ activeItem.title }} Settings</span
                            >
                        </div>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="item in sidebarNavItems"
                            :key="toUrl(item.href)"
                            :value="toUrl(item.href)"
                        >
                            <div class="flex items-center gap-2">
                                <component
                                    :is="item.icon"
                                    class="h-3.5 w-3.5 text-muted-foreground"
                                />
                                <span>{{ item.title }}</span>
                            </div>
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <!-- Horizontal Scrollable Pills with Scroll Hint Gradients -->
            <div class="relative w-full min-w-0">
                <!-- Left Scroll Hint Fade -->
                <div
                    v-if="canScrollLeft"
                    class="pointer-events-none absolute top-0 bottom-0 left-0 z-10 w-6 bg-gradient-to-r from-background via-background/80 to-transparent transition-opacity"
                />

                <nav
                    ref="navScrollRef"
                    class="no-scrollbar flex w-full min-w-0 touch-pan-x gap-1.5 overflow-x-auto overscroll-x-contain rounded-xl border border-border/60 bg-muted/40 p-1.5"
                    aria-label="Settings mobile navigation"
                    @scroll.passive="updateScrollIndicators"
                >
                    <Link
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        :href="item.href"
                        :data-active="isCurrentOrParentUrl(item.href)"
                        :class="[
                            'flex shrink-0 items-center gap-2 rounded-lg px-3 py-1.5 text-xs font-medium transition-all duration-150',
                            isCurrentOrParentUrl(item.href)
                                ? 'border border-border/80 bg-background text-foreground shadow-2xs'
                                : 'text-muted-foreground hover:bg-background/50 hover:text-foreground',
                        ]"
                    >
                        <component :is="item.icon" class="h-3.5 w-3.5" />
                        <span>{{ item.title }}</span>
                    </Link>
                </nav>

                <!-- Right Scroll Hint Fade -->
                <div
                    v-if="canScrollRight"
                    class="pointer-events-none absolute top-0 right-0 bottom-0 z-10 w-6 bg-gradient-to-l from-background via-background/80 to-transparent transition-opacity"
                />
            </div>
        </div>

        <!-- Desktop Navigation & Content Grid -->
        <div
            class="flex w-full min-w-0 flex-col lg:flex-row lg:items-start lg:gap-10"
        >
            <!-- Desktop Sidebar Rail -->
            <aside class="hidden lg:block lg:w-60 lg:shrink-0">
                <div class="sticky top-6 space-y-4">
                    <p
                        class="px-2 text-[11px] font-semibold tracking-wider text-muted-foreground/70 uppercase"
                    >
                        Preferences
                    </p>
                    <nav
                        class="flex flex-col space-y-1"
                        aria-label="Settings navigation"
                    >
                        <Link
                            v-for="item in sidebarNavItems"
                            :key="toUrl(item.href)"
                            :href="item.href"
                            :class="[
                                'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-150',
                                isCurrentOrParentUrl(item.href)
                                    ? 'bg-accent font-semibold text-accent-foreground shadow-2xs'
                                    : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                            ]"
                        >
                            <div
                                :class="[
                                    'flex h-7 w-7 items-center justify-center rounded-md transition-colors',
                                    isCurrentOrParentUrl(item.href)
                                        ? 'bg-primary/10 text-primary'
                                        : 'text-muted-foreground group-hover:text-foreground',
                                ]"
                            >
                                <component :is="item.icon" class="h-4 w-4" />
                            </div>
                            <span>{{ item.title }}</span>
                        </Link>
                    </nav>
                </div>
            </aside>

            <!-- Main Settings Content Area -->
            <main class="w-full max-w-3xl min-w-0 flex-1">
                <section class="w-full min-w-0 space-y-6 sm:space-y-8">
                    <slot />
                </section>
            </main>
        </div>
    </div>
</template>
