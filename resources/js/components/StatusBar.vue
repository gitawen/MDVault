<script setup lang="ts">
import { computed } from 'vue';
import type { SystemStatus } from '@/types';

const { status } = defineProps<{
    status: SystemStatus;
}>();

const runtimeLabel = computed(() =>
    status.runtime === 'desktop' ? 'Desktop' : 'Browser',
);

const databaseLabel = computed(() =>
    status.database.connected
        ? `${status.database.driver} · Connected`
        : 'Database unavailable',
);
</script>

<template>
    <footer
        class="flex items-center justify-between gap-2 border-t border-border/70 bg-card/40 px-3 py-1.5 text-[11px] text-muted-foreground select-none sm:px-4 sm:text-xs"
    >
        <div class="flex items-center gap-3 sm:gap-4">
            <span class="font-medium">{{ runtimeLabel }}</span>
            <span class="flex items-center gap-1.5">
                <span
                    class="size-1.5 shrink-0 rounded-full"
                    :class="
                        status.database.connected
                            ? 'bg-emerald-500 shadow-xs'
                            : 'bg-destructive'
                    "
                />
                <span class="max-w-[120px] truncate sm:max-w-none">{{
                    databaseLabel
                }}</span>
            </span>
        </div>
        <div class="shrink-0 font-mono text-[11px]">
            {{ status.application }}
            <span class="hidden sm:inline">v{{ status.version }}</span>
        </div>
    </footer>
</template>
