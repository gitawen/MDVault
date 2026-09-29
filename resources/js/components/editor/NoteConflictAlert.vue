<script setup lang="ts">
import { ref } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

defineProps<{
    conflict: { reason: 'changed' | 'missing'; currentHash: string | null };
    message: string;
}>();

const emit = defineEmits<{
    reload: [];
    overwrite: [];
    copy: [];
    reindex: [];
}>();

const reloadConfirmOpen = ref(false);
const overwriteConfirmOpen = ref(false);

function confirmReload(): void {
    reloadConfirmOpen.value = false;
    emit('reload');
}

function confirmOverwrite(): void {
    overwriteConfirmOpen.value = false;
    emit('overwrite');
}
</script>

<template>
    <div class="contents">
        <Alert variant="destructive">
            <AlertDescription class="flex flex-col gap-3">
                <span>{{ message }}</span>

                <div class="flex flex-wrap gap-2">
                    <template v-if="conflict.reason === 'changed'">
                        <Button
                            size="sm"
                            variant="outline"
                            @click="reloadConfirmOpen = true"
                        >
                            Reload from disk
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="overwriteConfirmOpen = true"
                        >
                            Keep my version
                        </Button>
                        <Button size="sm" variant="ghost" @click="emit('copy')">
                            Copy my text
                        </Button>
                    </template>

                    <template v-else>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="emit('copy')"
                        >
                            Copy my text
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="emit('reindex')"
                        >
                            Re-index vault
                        </Button>
                    </template>
                </div>
            </AlertDescription>
        </Alert>

        <Dialog v-model:open="reloadConfirmOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Reload from disk?</DialogTitle>
                    <DialogDescription>
                        Discard your unsaved edits and load the version on disk?
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        @click="reloadConfirmOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button variant="destructive" @click="confirmReload">
                        Reload
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="overwriteConfirmOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Keep my version?</DialogTitle>
                    <DialogDescription>
                        Replace the file on disk with your version? The changes
                        made outside MDVault will be lost.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        @click="overwriteConfirmOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button variant="destructive" @click="confirmOverwrite">
                        Keep mine
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
