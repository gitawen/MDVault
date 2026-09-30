<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { diffLines } from 'diff';
import { computed, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { show } from '@/routes/notes/disk';
import type { NoteDiskResponse } from '@/types';

const props = defineProps<{
    noteUuid: string;
    title: string;
    mine: string;
}>();

const open = defineModel<boolean>('open', { default: false });

type LoadState =
    | { status: 'loading' }
    | { status: 'error' }
    | { status: 'missing' }
    | { status: 'ready'; content: string };

const state = ref<LoadState>({ status: 'loading' });

function load(): void {
    state.value = { status: 'loading' };

    useHttp<Record<string, never>, NoteDiskResponse>({}).get(
        show.url(props.noteUuid),
        {
            onSuccess: (response) => {
                if (response.state === 'ok' && response.content !== null) {
                    state.value = {
                        status: 'ready',
                        content: response.content,
                    };
                } else {
                    state.value = { status: 'missing' };
                }
            },
            onError: () => {
                state.value = { status: 'error' };
            },
            onHttpException: () => {
                state.value = { status: 'error' };
            },
            onNetworkError: () => {
                state.value = { status: 'error' };
            },
        },
    );
}

watch(open, (isOpen) => {
    if (isOpen) {
        load();
    }
});

type DiffLine = { text: string; kind: 'added' | 'removed' | 'unchanged' };

const lines = computed<DiffLine[]>(() => {
    if (state.value.status !== 'ready') {
        return [];
    }

    const changes = diffLines(state.value.content, props.mine);
    const result: DiffLine[] = [];

    for (const change of changes) {
        const kind = change.added
            ? 'added'
            : change.removed
              ? 'removed'
              : 'unchanged';
        const changeLines = change.value.split('\n');

        if (changeLines.at(-1) === '') {
            changeLines.pop();
        }

        for (const text of changeLines) {
            result.push({ text, kind });
        }
    }

    return result;
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-3xl">
            <DialogHeader>
                <DialogTitle
                    >Compare &#8220;{{ title }}&#8221;: on disk &#8594; your
                    version</DialogTitle
                >
            </DialogHeader>

            <p
                v-if="state.status === 'loading'"
                class="text-sm text-muted-foreground"
            >
                Loading the file on disk&#8230;
            </p>
            <p
                v-else-if="state.status === 'error'"
                class="text-sm text-destructive"
            >
                MDVault couldn&#8217;t read the file on disk.
            </p>
            <p
                v-else-if="state.status === 'missing'"
                class="text-sm text-muted-foreground"
            >
                The file is no longer on disk.
            </p>
            <pre
                v-else
                class="max-h-[70vh] overflow-auto rounded-md border bg-muted/30 p-3 font-mono text-xs"
            ><span
                    v-for="(line, index) in lines"
                    :key="index"
                    class="block"
                    :class="{
                        'bg-emerald-500/10': line.kind === 'added',
                        'bg-red-500/10': line.kind === 'removed',
                        'text-muted-foreground': line.kind === 'unchanged',
                    }"
                >{{
                        line.kind === 'added'
                            ? '+ '
                            : line.kind === 'removed'
                              ? '- '
                              : '  '
                    }}{{ line.text }}</span></pre>
        </DialogContent>
    </Dialog>
</template>
