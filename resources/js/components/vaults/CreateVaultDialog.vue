<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
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
import { store } from '@/routes/vaults';

const props = defineProps<{
    storageRoot: string;
}>();

const open = ref(false);

const form = useForm({
    name: '',
    description: '',
});

function submit() {
    form.submit(store(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
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
                    <InputError :message="form.errors.name" />
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
                    <InputError :message="form.errors.description" />
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        Create vault
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
