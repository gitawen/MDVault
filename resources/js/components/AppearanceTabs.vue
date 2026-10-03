<script setup lang="ts">
import { Check, Monitor, Moon, Sun } from '@lucide/vue';
import { useAppearance } from '@/composables/useAppearance';

const { appearance, updateAppearance } = useAppearance();

const themes = [
    {
        value: 'light',
        Icon: Sun,
        label: 'Light',
        description: 'Clean, high-contrast crisp theme for bright daylight environments.',
    },
    {
        value: 'dark',
        Icon: Moon,
        label: 'Dark',
        description: 'Deep, soothing dark palette that reduces eye strain in low-light environments.',
    },
    {
        value: 'system',
        Icon: Monitor,
        label: 'System',
        description: 'Automatically synchronizes with your device or operating system preferences.',
    },
] as const;
</script>

<template>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <button
            v-for="{ value, Icon, label, description } in themes"
            :key="value"
            type="button"
            @click="updateAppearance(value)"
            :class="[
                'group relative flex flex-col overflow-hidden rounded-xl border p-4 text-left transition-all duration-200 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                appearance === value
                    ? 'border-primary bg-primary/[0.03] shadow-xs ring-1 ring-primary'
                    : 'border-border/60 bg-card hover:border-border hover:bg-muted/30',
            ]"
        >
            <!-- Miniature Window Theme Mockup -->
            <div
                class="relative mb-3.5 h-28 w-full overflow-hidden rounded-lg border shadow-2xs transition-transform duration-200 group-hover:scale-[1.01]"
                :class="[
                    value === 'light'
                        ? 'border-neutral-200 bg-neutral-100'
                        : value === 'dark'
                          ? 'border-neutral-800 bg-neutral-950'
                          : 'border-neutral-300 dark:border-neutral-800 bg-gradient-to-r from-neutral-100 via-neutral-100/50 to-neutral-950/90',
                ]"
            >
                <!-- Light Preview -->
                <template v-if="value === 'light'">
                    <div class="flex h-full w-full">
                        <!-- Mini sidebar -->
                        <div class="w-1/3 border-r border-neutral-200 bg-white p-2">
                            <div class="h-2 w-10 rounded-full bg-neutral-200 mb-2" />
                            <div class="space-y-1">
                                <div class="h-1.5 w-full rounded-sm bg-neutral-200/80" />
                                <div class="h-1.5 w-3/4 rounded-sm bg-neutral-200/60" />
                                <div class="h-1.5 w-4/5 rounded-sm bg-neutral-200/60" />
                            </div>
                        </div>
                        <!-- Mini editor -->
                        <div class="flex-1 bg-white p-2.5">
                            <div class="h-2.5 w-16 rounded-full bg-neutral-800 mb-2" />
                            <div class="space-y-1.5">
                                <div class="h-1.5 w-full rounded-sm bg-neutral-200" />
                                <div class="h-1.5 w-5/6 rounded-sm bg-neutral-200" />
                                <div class="h-1.5 w-2/3 rounded-sm bg-neutral-200" />
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Dark Preview -->
                <template v-else-if="value === 'dark'">
                    <div class="flex h-full w-full">
                        <!-- Mini sidebar -->
                        <div class="w-1/3 border-r border-neutral-800 bg-neutral-900 p-2">
                            <div class="h-2 w-10 rounded-full bg-neutral-700 mb-2" />
                            <div class="space-y-1">
                                <div class="h-1.5 w-full rounded-sm bg-neutral-700/80" />
                                <div class="h-1.5 w-3/4 rounded-sm bg-neutral-700/60" />
                                <div class="h-1.5 w-4/5 rounded-sm bg-neutral-700/60" />
                            </div>
                        </div>
                        <!-- Mini editor -->
                        <div class="flex-1 bg-neutral-900 p-2.5">
                            <div class="h-2.5 w-16 rounded-full bg-neutral-200 mb-2" />
                            <div class="space-y-1.5">
                                <div class="h-1.5 w-full rounded-sm bg-neutral-700" />
                                <div class="h-1.5 w-5/6 rounded-sm bg-neutral-700" />
                                <div class="h-1.5 w-2/3 rounded-sm bg-neutral-700" />
                            </div>
                        </div>
                    </div>
                </template>

                <!-- System Split Preview -->
                <template v-else>
                    <div class="grid h-full w-full grid-cols-2">
                        <!-- Left half: Light -->
                        <div class="flex h-full w-full border-r border-neutral-300 bg-white p-2">
                            <div class="w-full space-y-1.5">
                                <div class="h-2 w-12 rounded-full bg-neutral-800 mb-2" />
                                <div class="h-1.5 w-full rounded-sm bg-neutral-200" />
                                <div class="h-1.5 w-4/5 rounded-sm bg-neutral-200" />
                            </div>
                        </div>
                        <!-- Right half: Dark -->
                        <div class="flex h-full w-full bg-neutral-900 p-2">
                            <div class="w-full space-y-1.5">
                                <div class="h-2 w-12 rounded-full bg-neutral-200 mb-2" />
                                <div class="h-1.5 w-full rounded-sm bg-neutral-700" />
                                <div class="h-1.5 w-4/5 rounded-sm bg-neutral-700" />
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Active Check Badge -->
                <div
                    v-if="appearance === value"
                    class="absolute top-2 right-2 flex h-5 w-5 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-xs"
                >
                    <Check class="h-3 w-3 stroke-[3]" />
                </div>
            </div>

            <!-- Card Meta & Label -->
            <div class="flex items-center gap-2">
                <component
                    :is="Icon"
                    class="h-4 w-4"
                    :class="appearance === value ? 'text-primary' : 'text-muted-foreground'"
                />
                <span class="text-sm font-semibold tracking-tight text-foreground">
                    {{ label }}
                </span>
            </div>
            <p class="mt-1 text-xs text-muted-foreground leading-relaxed">
                {{ description }}
            </p>
        </button>
    </div>
</template>
