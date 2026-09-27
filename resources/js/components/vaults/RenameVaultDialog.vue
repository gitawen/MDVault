<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
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
import { update } from '@/routes/vaults';
import type { VaultSummary } from '@/types';

const props = defineProps<{
    vault: VaultSummary;
}>();

const open = ref(false);

const form = useForm({
    name: props.vault.name,
    description: props.vault.description ?? '',
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.name = props.vault.name;
        form.description = props.vault.description ?? '';
        form.clearErrors();
    }
});

function submit() {
    form.submit(update(props.vault.uuid), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button variant="outline" size="sm">Rename</Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Rename vault</DialogTitle>
                <DialogDescription>
                    Renaming also renames the vault's folder on disk. Close any
                    programs using the folder first (File Explorer / Finder, VS
                    Code, sync tools). Current folder:
                    {{ vault.path }}
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label :for="`rename-vault-name-${vault.uuid}`">
                        Name
                    </Label>
                    <Input
                        :id="`rename-vault-name-${vault.uuid}`"
                        v-model="form.name"
                        type="text"
                        autocomplete="off"
                        :disabled="vault.status === 'missing'"
                    />
                    <p
                        v-if="vault.status === 'missing'"
                        class="text-sm text-muted-foreground"
                    >
                        The folder is missing, so only the description can be
                        changed.
                    </p>
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label :for="`rename-vault-description-${vault.uuid}`">
                        Description (optional)
                    </Label>
                    <Input
                        :id="`rename-vault-description-${vault.uuid}`"
                        v-model="form.description"
                        type="text"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.description" />
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        Save
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
