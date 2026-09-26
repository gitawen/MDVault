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
        class="flex items-center justify-between gap-4 border-t px-4 py-1.5 text-xs text-muted-foreground"
    >
        <div class="flex items-center gap-4">
            <span>{{ runtimeLabel }}</span>
            <span class="flex items-center gap-1.5">
                <span
                    class="size-1.5 rounded-full"
                    :class="
                        status.database.connected
                            ? 'bg-green-500'
                            : 'bg-red-500'
                    "
                />
                {{ databaseLabel }}
            </span>
        </div>
        <div>{{ status.application }} v{{ status.version }}</div>
    </footer>
</template>
