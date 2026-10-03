<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
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
import { setToken } from '@/lib/vault/keyring';
import { store } from '@/routes/vaults/encryption';
import type { VaultSummary } from '@/types';

const props = defineProps<{
    vault: VaultSummary;
}>();

const open = ref(false);

// Passwords live only in this form's memory: no remember key, never written
// to storage, and cleared as soon as the request finishes.
const form = useHttp<
    {
        password: string;
        password_confirmation: string;
        acknowledge: boolean;
        acknowledge_delete: boolean;
    },
    { token: string | null; warning: string | null }
>({
    password: '',
    password_confirmation: '',
    acknowledge: false,
    acknowledge_delete: false,
}).dontRemember('password', 'password_confirmation');

// The error keys the server uses beyond the form's own fields.
const vaultError = computed(
    () => (form.errors as Record<string, string | undefined>).vault,
);

// A refused conversion reports one `problem_<n>` error per file it can't
// encrypt yet.
const problems = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('problem_'))
        .map(([, message]) => message),
);

function submit() {
    form.clearErrors();

    void form.post(store.url(props.vault.uuid), {
        onSuccess: (response) => {
            if (response.token) {
                setToken(props.vault.uuid, response.token);
            }

            open.value = false;
            form.reset();
            router.reload();
        },
        onFinish: () => {
            form.password = '';
            form.password_confirmation = '';
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
                Encrypt
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Encrypt “{{ vault.name }}”</DialogTitle>
                <DialogDescription>
                    Notes and folder names are encrypted with a password. The
                    vault name stays visible.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <p
                    class="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm"
                >
                    Your existing unencrypted notes in this folder will be
                    permanently deleted after encryption. Copies in backups,
                    cloud sync history or the Recycle Bin are not affected, and
                    deleted files may be recoverable with forensic tools. For
                    your most sensitive notes, create a new encrypted vault
                    instead.
                </p>

                <div class="grid gap-2">
                    <Label :for="`encrypt-vault-password-${vault.uuid}`">
                        Password
                    </Label>
                    <Input
                        :id="`encrypt-vault-password-${vault.uuid}`"
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                    />
                    <p class="text-sm text-muted-foreground">
                        At least 10 characters.
                    </p>
                    <InputError :message="form.errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label :for="`encrypt-vault-confirmation-${vault.uuid}`">
                        Confirm password
                    </Label>
                    <Input
                        :id="`encrypt-vault-confirmation-${vault.uuid}`"
                        v-model="form.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                    />
                </div>

                <div class="space-y-1">
                    <div class="flex items-start gap-2">
                        <Checkbox
                            :id="`encrypt-vault-acknowledge-${vault.uuid}`"
                            class="mt-0.5"
                            :model-value="form.acknowledge"
                            @update:model-value="
                                (value) => (form.acknowledge = value === true)
                            "
                        />
                        <Label :for="`encrypt-vault-acknowledge-${vault.uuid}`">
                            If I forget this password, my notes can't be
                            recovered. I understand.
                        </Label>
                    </div>
                    <InputError :message="form.errors.acknowledge" />
                </div>

                <div class="space-y-1">
                    <div class="flex items-start gap-2">
                        <Checkbox
                            :id="`encrypt-vault-delete-${vault.uuid}`"
                            class="mt-0.5"
                            :model-value="form.acknowledge_delete"
                            @update:model-value="
                                (value) =>
                                    (form.acknowledge_delete = value === true)
                            "
                        />
                        <Label :for="`encrypt-vault-delete-${vault.uuid}`">
                            Permanently delete the unencrypted originals after
                            the notes are encrypted.
                        </Label>
                    </div>
                    <InputError :message="form.errors.acknowledge_delete" />
                </div>

                <div v-if="vaultError" class="space-y-2 text-sm" role="alert">
                    <InputError :message="vaultError" />
                    <ul
                        v-if="problems.length > 0"
                        class="list-disc space-y-1 pl-5 text-muted-foreground"
                    >
                        <li v-for="problem in problems" :key="problem">
                            {{ problem }}
                        </li>
                    </ul>
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        {{
                            form.processing
                                ? 'Encrypting…'
                                : 'Encrypt this vault'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
