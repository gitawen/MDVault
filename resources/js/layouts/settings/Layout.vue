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
    <div class="mx-auto w-full min-w-0 max-w-5xl px-3 py-4 sm:px-6 sm:py-6 md:px-8 md:py-8">
        <!-- Settings Page Header -->
        <header class="mb-6 sm:mb-8 border-b border-border/50 pb-5 sm:pb-6">
            <div class="flex items-center gap-3">
                <div
                    class="flex h-9 w-9 sm:h-10 sm:w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary border border-primary/20 shadow-2xs"
                >
                    <SlidersHorizontal class="h-4.5 w-4.5 sm:h-5 sm:w-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <h1 class="text-xl sm:text-2xl font-semibold tracking-tight truncate">
                        Settings
                    </h1>
                    <p class="text-xs sm:text-sm text-muted-foreground line-clamp-2 sm:line-clamp-none">
                        Manage your vault preferences, editor typography, and storage configurations.
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
                    <SelectTrigger class="w-full h-10 bg-card border-border/70 text-xs font-medium">
                        <div class="flex items-center gap-2 truncate">
                            <component
                                :is="activeItem.icon"
                                class="h-3.5 w-3.5 text-primary shrink-0"
                            />
                            <span class="truncate">{{ activeItem.title }} Settings</span>
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
                    class="pointer-events-none absolute left-0 top-0 bottom-0 z-10 w-6 bg-gradient-to-r from-background via-background/80 to-transparent transition-opacity"
                />

                <nav
                    ref="navScrollRef"
                    class="flex w-full min-w-0 gap-1.5 overflow-x-auto rounded-xl border border-border/60 bg-muted/40 p-1.5 no-scrollbar touch-pan-x overscroll-x-contain"
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
                                ? 'bg-background text-foreground shadow-2xs border border-border/80'
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
                    class="pointer-events-none absolute right-0 top-0 bottom-0 z-10 w-6 bg-gradient-to-l from-background via-background/80 to-transparent transition-opacity"
                />
            </div>
        </div>

        <!-- Desktop Navigation & Content Grid -->
        <div class="flex w-full min-w-0 flex-col lg:flex-row lg:items-start lg:gap-10">
            <!-- Desktop Sidebar Rail -->
            <aside class="hidden lg:block lg:w-60 lg:shrink-0">
                <div class="sticky top-6 space-y-4">
                    <p class="px-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/70">
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
                                    ? 'bg-accent text-accent-foreground shadow-2xs font-semibold'
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
            <main class="w-full flex-1 min-w-0 max-w-3xl">
                <section class="w-full min-w-0 space-y-6 sm:space-y-8">
                    <slot />
                </section>
            </main>
        </div>
    </div>
</template>
