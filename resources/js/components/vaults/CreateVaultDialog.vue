<script setup lang="ts">
import { router, useForm, useHttp } from '@inertiajs/vue3';
import { ref } from 'vue';
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
import { workspace } from '@/routes';
import { store } from '@/routes/vaults';
import { store as storeEncrypted } from '@/routes/vaults/encrypted';

const props = defineProps<{
    storageRoot: string;
}>();

const open = ref(false);
const encrypt = ref(false);

const form = useForm({
    name: '',
    description: '',
});

// Passwords live only in this form's memory: no remember key, never written
// to storage, and cleared as soon as the request finishes.
const encryptedForm = useHttp<
    {
        name: string;
        description: string;
        password: string;
        password_confirmation: string;
        acknowledge: boolean;
    },
    { uuid: string; token: string }
>({
    name: '',
    description: '',
    password: '',
    password_confirmation: '',
    acknowledge: false,
}).dontRemember('password', 'password_confirmation');

function reset() {
    form.reset();
    encryptedForm.reset();
    encryptedForm.clearErrors();
    encrypt.value = false;
}

function submit() {
    if (encrypt.value) {
        submitEncrypted();

        return;
    }

    form.submit(store(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
}

function submitEncrypted() {
    encryptedForm.clearErrors();
    encryptedForm.name = form.name;
    encryptedForm.description = form.description;

    void encryptedForm.post(storeEncrypted.url(), {
        onSuccess: (response) => {
            setToken(response.uuid, response.token);
            open.value = false;
            reset();
            router.visit(workspace());
        },
        onFinish: () => {
            encryptedForm.password = '';
            encryptedForm.password_confirmation = '';
        },
    });
}

function nameError(): string | undefined {
    return encrypt.value ? encryptedForm.errors.name : form.errors.name;
}

function descriptionError(): string | undefined {
    return encrypt.value
        ? encryptedForm.errors.description
        : form.errors.description;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button>New vault</Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Create a vault</DialogTitle>
                <DialogDescription>
                    The folder is created inside: {{ props.storageRoot }}
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="create-vault-name">Name</Label>
                    <Input
                        id="create-vault-name"
                        v-model="form.name"
                        type="text"
                        autocomplete="off"
                    />
                    <InputError :message="nameError()" />
                </div>

                <div class="grid gap-2">
                    <Label for="create-vault-description">
                        Description (optional)
                    </Label>
                    <Input
                        id="create-vault-description"
                        v-model="form.description"
                        type="text"
                        autocomplete="off"
                    />
                    <InputError :message="descriptionError()" />
                </div>

                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <Checkbox
                            id="create-vault-encrypt"
                            :model-value="encrypt"
                            @update:model-value="
                                (value) => (encrypt = value === true)
                            "
                        />
                        <Label for="create-vault-encrypt">
                            Encrypt this vault
                        </Label>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Notes and folder names are encrypted with a password.
                        The vault name stays visible.
                    </p>
                </div>

                <div v-if="encrypt" class="space-y-4">
                    <div class="grid gap-2">
                        <Label for="create-vault-password">Password</Label>
                        <Input
                            id="create-vault-password"
                            v-model="encryptedForm.password"
                            type="password"
                            autocomplete="new-password"
                        />
                        <p class="text-sm text-muted-foreground">
                            At least 10 characters.
                        </p>
                        <InputError :message="encryptedForm.errors.password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="create-vault-password-confirmation">
                            Confirm password
                        </Label>
                        <Input
                            id="create-vault-password-confirmation"
                            v-model="encryptedForm.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                        />
                    </div>

                    <div class="space-y-1">
                        <div class="flex items-start gap-2">
                            <Checkbox
                                id="create-vault-acknowledge"
                                class="mt-0.5"
                                :model-value="encryptedForm.acknowledge"
                                @update:model-value="
                                    (value) =>
                                        (encryptedForm.acknowledge =
                                            value === true)
                                "
                            />
                            <Label for="create-vault-acknowledge">
                                If you forget this password, your notes can't be
                                recovered. I understand.
                            </Label>
                        </div>
                        <InputError
                            :message="encryptedForm.errors.acknowledge"
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="form.processing || encryptedForm.processing"
                    >
                        {{
                            encrypt ? 'Create encrypted vault' : 'Create vault'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
