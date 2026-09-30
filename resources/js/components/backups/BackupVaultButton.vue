<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { store } from '@/routes/settings/backup';

const props = defineProps<{
    vault: { uuid: string; status: string };
}>();

const backingUp = ref(false);

function backUp(): void {
    backingUp.value = true;

    router.post(
        store.url(),
        { vault: props.vault.uuid },
        {
            preserveScroll: true,
            onFinish: () => {
                backingUp.value = false;
            },
        },
    );
}
</script>

<template>
    <Button
        size="sm"
        variant="outline"
        :disabled="vault.status !== 'active' || backingUp"
        @click="backUp"
    >
        Back up
    </Button>
</template>
