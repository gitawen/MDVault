<script setup lang="ts">
import { computed, ref } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    paths: string[];
}>();

// Module-level (not per-instance): a dismissal is remembered for the whole
// session, across Workspace remounts, per FR-19/G7.
const dismissedPaths = new Set<string>();
const version = ref(0);

const visiblePaths = computed<string[]>(() => {
    void version.value;

    return props.paths.filter((path) => !dismissedPaths.has(path));
});

function dismiss(): void {
    for (const path of props.paths) {
        dismissedPaths.add(path);
    }

    version.value++;
}
</script>

<template>
    <Alert v-if="visiblePaths.length > 0">
        <AlertDescription class="flex flex-col gap-2">
            <span>
                An interrupted save left a recovery file in this vault. It may
                hold text you typed. Open it in a text editor to recover it;
                MDVault never deletes these files.
            </span>
            <ul class="list-disc pl-5 font-mono text-xs">
                <li v-for="path in visiblePaths" :key="path">{{ path }}</li>
            </ul>
            <div>
                <Button size="sm" variant="outline" @click="dismiss">
                    Dismiss
                </Button>
            </div>
        </AlertDescription>
    </Alert>
</template>
