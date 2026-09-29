<script setup lang="ts">
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
    message: string;
}>();

const emit = defineEmits<{
    stay: [];
    discard: [];
}>();

const open = defineModel<boolean>('open', { default: false });

function stay(): void {
    open.value = false;
    emit('stay');
}

function discard(): void {
    open.value = false;
    emit('discard');
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Unsaved changes</DialogTitle>
                <DialogDescription>
                    Your latest changes couldn&#8217;t be saved: {{ message }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="stay">Stay</Button>
                <Button variant="destructive" @click="discard">
                    Discard changes
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
