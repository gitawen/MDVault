<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Folder, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import DirectoryBrowserDialog from '@/components/DirectoryBrowserDialog.vue';
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
import { batch, browse, store } from '@/routes/vaults/existing';

const props = defineProps<{
    canBrowse: boolean;
}>();

const open = ref(false);
const browsing = ref(false);
const browserOpen = ref(false);
const mode = ref<'single' | 'batch'>('single');

const form = useForm({
    path: '',
    name: '',
    description: '',
});

const batchForm = useForm({
    paths: [] as string[],
});

type PickedFolderFlash = {
    pickedFolder?: { path: string; name: string };
};

function suggestedNameFrom(path: string): string {
    const normalized = path.replace(/\\/g, '/');
    const parts = normalized.split('/').filter(Boolean);
    return parts.length > 0 ? parts[parts.length - 1] : '';
}

function onChooseFolderClick() {
    if (props.canBrowse) {
        chooseFolder();
    } else {
        browserOpen.value = true;
    }
}

function onFoldersSelected(paths: string[]) {
    if (paths.length === 1) {
        mode.value = 'single';
        form.path = paths[0];
        if (!form.name) {
            form.name = suggestedNameFrom(paths[0]);
        }
    } else if (paths.length > 1) {
        mode.value = 'batch';
        batchForm.paths = [...paths];
    }
}

function removeBatchFolder(index: number) {
    batchForm.paths.splice(index, 1);
    if (batchForm.paths.length === 1) {
        mode.value = 'single';
        form.path = batchForm.paths[0];
        form.name = suggestedNameFrom(batchForm.paths[0]);
        batchForm.reset();
    } else if (batchForm.paths.length === 0) {
        mode.value = 'single';
        form.reset();
        batchForm.reset();
    }
}

function switchToSingle() {
    mode.value = 'single';
    if (batchForm.paths.length > 0) {
        form.path = batchForm.paths[0];
        form.name = suggestedNameFrom(batchForm.paths[0]);
    }
    batchForm.reset();
}

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
                    mode.value = 'single';
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

function submitSingle() {
    form.submit(store(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
            mode.value = 'single';
        },
    });
}

function submitBatch() {
    batchForm.submit(batch(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            batchForm.reset();
            form.reset();
            mode.value = 'single';
        },
    });
}

watch(open, (isOpen) => {
    if (!isOpen) {
        form.reset();
        batchForm.reset();
        mode.value = 'single';
    }
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button variant="outline">Add existing folder</Button>
        </DialogTrigger>
        <DialogContent>
            <!-- Single Mode -->
            <template v-if="mode === 'single'">
                <DialogHeader>
                    <DialogTitle>Add an existing folder</DialogTitle>
                    <DialogDescription>
                        The folder and its files are left as they are.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submitSingle">
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
                                type="button"
                                variant="outline"
                                :disabled="browsing"
                                @click="onChooseFolderClick"
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
            </template>

            <!-- Batch Mode -->
            <template v-else>
                <DialogHeader>
                    <DialogTitle>Add existing folders</DialogTitle>
                    <DialogDescription>
                        Review the {{ batchForm.paths.length }} folders to add as
                        vaults.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-3">
                    <div
                        class="flex items-center justify-between text-xs text-muted-foreground"
                    >
                        <span>
                            {{ batchForm.paths.length }} folder{{
                                batchForm.paths.length === 1 ? '' : 's'
                            }}
                            selected
                        </span>
                        <div class="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="h-7 text-xs"
                                @click="browserOpen = true"
                            >
                                Choose different folders…
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="h-7 text-xs text-muted-foreground"
                                @click="switchToSingle"
                            >
                                Switch to single folder
                            </Button>
                        </div>
                    </div>

                    <!-- Scrollable list of selected folders -->
                    <div
                        class="max-h-[260px] space-y-1.5 overflow-y-auto rounded-md border border-border/60 bg-muted/20 p-2 text-xs"
                    >
                        <div
                            v-for="(path, index) in batchForm.paths"
                            :key="path"
                            class="flex items-center justify-between gap-2 rounded border border-border/50 bg-card p-2"
                        >
                            <div class="flex min-w-0 flex-1 items-center gap-2">
                                <Folder
                                    class="size-4 shrink-0 fill-amber-500/20 text-amber-500"
                                />
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="truncate font-medium text-foreground"
                                    >
                                        {{ suggestedNameFrom(path) }}
                                    </div>
                                    <div
                                        class="truncate font-mono text-[11px] text-muted-foreground"
                                        :title="path"
                                    >
                                        {{ path }}
                                    </div>
                                </div>
                            </div>

                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="size-6 shrink-0 p-0 text-muted-foreground hover:text-destructive"
                                title="Remove folder from batch"
                                @click="removeBatchFolder(index)"
                            >
                                <X class="size-3.5" />
                            </Button>
                        </div>
                    </div>

                    <InputError :message="batchForm.errors.paths" />

                    <DialogFooter class="gap-2 sm:gap-0">
                        <Button
                            type="button"
                            variant="outline"
                            @click="open = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            :disabled="
                                batchForm.processing ||
                                batchForm.paths.length === 0
                            "
                            @click="submitBatch"
                        >
                            Add {{ batchForm.paths.length }} vault{{
                                batchForm.paths.length === 1 ? '' : 's'
                            }}
                        </Button>
                    </DialogFooter>
                </div>
            </template>
        </DialogContent>

        <DirectoryBrowserDialog
            v-model:open="browserOpen"
            title="Choose vault folder"
            description="Select existing folders containing Markdown notes."
            :multiple="true"
            :initial-path="form.path || null"
            @select="(path) => onFoldersSelected([path])"
            @select-multiple="onFoldersSelected"
        />
    </Dialog>
</template>
