<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { setToken } from '@/lib/vault/keyring';
import { unlock } from '@/routes/vaults';
import type { VaultSummary } from '@/types';

const props = defineProps<{
    vault: VaultSummary;
}>();

// Never given a remember key, and the password is also excluded from any
// remembered state: it must not outlive this panel.
const form = useHttp<{ password: string }, { token: string }>({
    password: '',
}).dontRemember('password');

function submit() {
    form.clearErrors();

    void form.post(unlock.url(props.vault.uuid), {
        onSuccess: (response) => {
            setToken(props.vault.uuid, response.token);
            form.reset();
            router.reload();
        },
        onError: () => {
            form.password = '';
        },
        onHttpException: () => {
            form.password = '';
            form.setError('password', "This vault couldn't be unlocked.");
        },
    });
}
</script>

<template>
    <div class="mx-auto flex w-full max-w-sm flex-col gap-4 py-10">
        <div class="flex flex-col items-center gap-2 text-center">
            <Lock class="size-8 text-muted-foreground" aria-hidden="true" />
            <h2 class="text-lg font-semibold">{{ vault.name }} is locked</h2>
            <p class="text-sm text-muted-foreground">
                The notes in this vault are encrypted. Enter its password to
                unlock it.
            </p>
        </div>

        <form class="space-y-3" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label :for="`unlock-vault-${vault.uuid}`">Password</Label>
                <Input
                    :id="`unlock-vault-${vault.uuid}`"
                    v-model="form.password"
                    v-focus
                    type="password"
                    autocomplete="current-password"
                    :aria-invalid="form.errors.password ? true : undefined"
                />
                <InputError :message="form.errors.password" />
            </div>

            <Button
                type="submit"
                class="w-full"
                :disabled="form.processing || form.password === ''"
            >
                Unlock
            </Button>
        </form>
    </div>
</template>
