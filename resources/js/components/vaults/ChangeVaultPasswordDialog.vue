<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
import { update } from '@/routes/vaults/encryption/password';
import type { VaultSummary } from '@/types';

const props = defineProps<{
    vault: VaultSummary;
}>();

const open = ref(false);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
}).dontRemember('current_password', 'password', 'password_confirmation');

// The server reports vault-level failures under `vault`, which isn't a
// form field.
const vaultError = computed(
    () => (form.errors as Record<string, string | undefined>).vault,
);

function submit() {
    form.submit(update(props.vault.uuid), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
        onFinish: () => {
            form.reset('current_password', 'password', 'password_confirmation');
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
                Change password
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle
                    >Change the password of “{{ vault.name }}”</DialogTitle
                >
                <DialogDescription>
                    Your notes are not re-encrypted, so this is instant. Older
                    backups still open with the old password.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label :for="`password-vault-current-${vault.uuid}`">
                        Current password
                    </Label>
                    <Input
                        :id="`password-vault-current-${vault.uuid}`"
                        v-model="form.current_password"
                        type="password"
                        autocomplete="current-password"
                    />
                    <InputError :message="form.errors.current_password" />
                </div>

                <div class="grid gap-2">
                    <Label :for="`password-vault-new-${vault.uuid}`">
                        New password
                    </Label>
                    <Input
                        :id="`password-vault-new-${vault.uuid}`"
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                    />
                    <p class="text-sm text-muted-foreground">
                        At least 10 characters. If you forget it, your notes
                        can't be recovered.
                    </p>
                    <InputError :message="form.errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label :for="`password-vault-confirmation-${vault.uuid}`">
                        Confirm new password
                    </Label>
                    <Input
                        :id="`password-vault-confirmation-${vault.uuid}`"
                        v-model="form.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                    />
                </div>

                <InputError :message="vaultError" />

                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        Change password
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
