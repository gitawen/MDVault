<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Archive,
    HardDrive,
    Palette,
    PenLine,
    ShieldCheck,
    SlidersHorizontal,
} from '@lucide/vue';
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

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="mx-auto max-w-5xl px-4 py-6 md:px-8 md:py-8">
        <!-- Settings Page Header -->
        <header class="mb-8 border-b border-border/50 pb-6">
            <div class="flex items-center gap-3">
                <div
                    class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary border border-primary/20 shadow-2xs"
                >
                    <SlidersHorizontal class="h-5 w-5" />
                </div>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Settings
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Manage your vault preferences, editor typography, and storage configurations.
                    </p>
                </div>
            </div>
        </header>

        <!-- Mobile Navigation (Horizontal Scrollable Pills) -->
        <div class="mb-6 lg:hidden">
            <nav
                class="flex gap-1.5 overflow-x-auto rounded-xl border border-border/60 bg-muted/40 p-1.5 no-scrollbar"
                aria-label="Settings mobile"
            >
                <Link
                    v-for="item in sidebarNavItems"
                    :key="toUrl(item.href)"
                    :href="item.href"
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
        </div>

        <!-- Desktop Navigation & Content Grid -->
        <div class="flex flex-col lg:flex-row lg:items-start lg:gap-10">
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
            <main class="flex-1 min-w-0 max-w-3xl">
                <section class="space-y-8">
                    <slot />
                </section>
            </main>
        </div>
    </div>
</template>
