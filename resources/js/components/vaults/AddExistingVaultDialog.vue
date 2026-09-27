<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
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
import { browse, store } from '@/routes/vaults/existing';

defineProps<{
    canBrowse: boolean;
}>();

const open = ref(false);
const browsing = ref(false);

const form = useForm({
    path: '',
    name: '',
    description: '',
});

type PickedFolderFlash = {
    pickedFolder?: { path: string; name: string };
};

function chooseFolder() {
    browsing.value = true;

    router.post(
        browse.url(),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            onFlash: (flash) => {
                const pickedFolder = (flash as PickedFolderFlash)?.pickedFolder;

                if (pickedFolder) {
                    form.path = pickedFolder.path;
                    if (!form.name) {
                        form.name = pickedFolder.name;
                    }
                }
            },
            onFinish: () => {
                browsing.value = false;
            },
        },
    );
}

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
            <Button variant="outline">Add existing folder</Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Add an existing folder</DialogTitle>
                <DialogDescription>
                    The folder and its files are left as they are.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="existing-vault-path">Folder path</Label>
                    <div class="flex gap-2">
                        <Input
                            id="existing-vault-path"
                            v-model="form.path"
                            type="text"
                            autocomplete="off"
                            class="flex-1"
                        />
                        <Button
                            v-if="canBrowse"
                            type="button"
                            variant="outline"
                            :disabled="browsing"
                            @click="chooseFolder"
                        >
                            Choose folder…
                        </Button>
                    </div>
                    <InputError :message="form.errors.path" />
                </div>

                <div class="grid gap-2">
                    <Label for="existing-vault-name">Name</Label>
                    <Input
                        id="existing-vault-name"
                        v-model="form.name"
                        type="text"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="existing-vault-description">
                        Description (optional)
                    </Label>
                    <Input
                        id="existing-vault-description"
                        v-model="form.description"
                        type="text"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.description" />
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        Add vault
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
