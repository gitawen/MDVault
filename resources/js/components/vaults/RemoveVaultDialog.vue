<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
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
import { Label } from '@/components/ui/label';
import { destroy } from '@/routes/vaults';
import type { VaultSummary } from '@/types';

const props = defineProps<{
    vault: VaultSummary;
    canTrash: boolean;
}>();

const open = ref(false);

const form = useForm({
    move_to_trash: false,
});

function submit() {
    form.submit(destroy(props.vault.uuid), {
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
            <Button variant="destructive" size="sm">Remove</Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Remove vault</DialogTitle>
                <DialogDescription>
                    Remove “{{ vault.name }}” from MDVault? Your files stay on
                    disk at {{ vault.path }}.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div
                    v-if="canTrash && vault.status === 'active'"
                    class="space-y-1"
                >
                    <div class="flex items-center gap-2">
                        <Checkbox
                            :id="`remove-vault-trash-${vault.uuid}`"
                            :model-value="form.move_to_trash"
                            @update:model-value="
                                (value) => (form.move_to_trash = value === true)
                            "
                        />
                        <Label :for="`remove-vault-trash-${vault.uuid}`">
                            Also move the folder to the Recycle Bin / Trash
                        </Label>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        You can restore it from the Recycle Bin / Trash.
                    </p>
                    <InputError :message="form.errors.move_to_trash" />
                </div>

                <DialogFooter>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="form.processing"
                    >
                        Remove vault
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
