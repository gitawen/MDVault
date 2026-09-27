<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import type { VaultStatus } from '@/types';

defineProps<{
    status: VaultStatus;
}>();
</script>

<template>
    <Badge v-if="status === 'active'" variant="secondary">Available</Badge>
    <Badge v-else variant="destructive" class="gap-1">
        <TriangleAlert class="size-3.5" />
        Folder missing
    </Badge>
</template>
