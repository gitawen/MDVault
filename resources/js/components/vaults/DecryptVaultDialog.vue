<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { forget } from '@/lib/vault/keyring';
import { destroy } from '@/routes/vaults/encryption';
import type { VaultSummary } from '@/types';

const props = defineProps<{
    vault: VaultSummary;
}>();

const open = ref(false);

const form = useForm({
    password: '',
    acknowledge: false,
}).dontRemember('password');

// The server reports vault-level failures under `vault`, which isn't a
// form field.
const vaultError = computed(
    () => (form.errors as Record<string, string | undefined>).vault,
);

function submit() {
    form.submit(destroy(props.vault.uuid), {
        preserveScroll: true,
        onSuccess: () => {
            forget(props.vault.uuid);
            open.value = false;
            form.reset();
        },
        onFinish: () => {
            form.reset('password');
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                variant="outline"
                size="sm"
                :disabled="vault.status !== 'active'"
            >
                Remove encryption
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle
                    >Remove encryption from “{{ vault.name }}”</DialogTitle
                >
                <DialogDescription>
                    Your notes become plain Markdown files again, readable by
                    any program that can open the folder.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label :for="`decrypt-vault-password-${vault.uuid}`">
                        Vault password
                    </Label>
                    <Input
                        :id="`decrypt-vault-password-${vault.uuid}`"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="space-y-1">
                    <div class="flex items-start gap-2">
                        <Checkbox
                            :id="`decrypt-vault-acknowledge-${vault.uuid}`"
                            class="mt-0.5"
                            :model-value="form.acknowledge"
                            @update:model-value="
                                (value) => (form.acknowledge = value === true)
                            "
                        />
                        <Label :for="`decrypt-vault-acknowledge-${vault.uuid}`">
                            Store my notes unencrypted from now on.
                        </Label>
                    </div>
                    <InputError :message="form.errors.acknowledge" />
                </div>

                <InputError :message="vaultError" />

                <DialogFooter>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="form.processing"
                    >
                        {{
                            form.processing
                                ? 'Decrypting…'
                                : 'Remove encryption'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
