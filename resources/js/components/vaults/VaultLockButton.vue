<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { forget } from '@/lib/vault/keyring';
import { lock } from '@/routes/vaults';

const props = defineProps<{
    vaultUuid: string;
}>();

const locking = ref(false);

function lockVault() {
    locking.value = true;

    router.post(
        lock.url(props.vaultUuid),
        {},
        {
            onSuccess: () => forget(props.vaultUuid),
            onFinish: () => {
                locking.value = false;
            },
        },
    );
}
</script>

<template>
    <Button
        variant="ghost"
        size="sm"
        class="shrink-0"
        :disabled="locking"
        @click="lockVault"
    >
        <Lock class="size-4" />
        Lock
    </Button>
</template>
