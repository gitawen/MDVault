<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { reindex } from '@/routes/vaults';
import type { NoteDetail } from '@/types';

const props = defineProps<{
    note: NoteDetail;
    vaultUuid: string;
}>();

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex++;
    }

    return `${value.toFixed(1)} ${units[unitIndex]}`;
}

function runReindex() {
    router.post(reindex.url(props.vaultUuid), {}, { preserveScroll: true });
}
</script>

<template>
    <article class="flex min-h-0 flex-col gap-3">
        <header class="flex items-center gap-2">
            <h1 class="text-lg font-semibold">{{ note.title }}</h1>
            <span class="truncate font-mono text-xs text-muted-foreground">
                {{ note.relative_path }}
            </span>
            <Badge variant="secondary">Read-only preview</Badge>
        </header>

        <template v-if="note.state === 'ok'">
            <p v-if="note.content === ''" class="text-sm text-muted-foreground">
                This note is empty.
            </p>
            <pre
                v-else
                class="rounded-md border bg-muted/30 p-4 font-mono text-sm break-words whitespace-pre-wrap"
                >{{ note.content }}</pre>

            <Alert v-if="!note.is_valid_utf8" variant="destructive">
                <AlertDescription>
                    This file isn&#8217;t valid UTF-8; unreadable characters are
                    shown as &#65533;.
                </AlertDescription>
            </Alert>
        </template>

        <Alert v-else-if="note.state === 'missing'" variant="destructive">
            <AlertDescription class="flex items-center gap-3">
                <span>
                    This note&#8217;s file can&#8217;t be found on disk. It may
                    have been moved, renamed or deleted outside MDVault.
                </span>
                <Button size="sm" variant="outline" @click="runReindex">
                    Re-index
                </Button>
            </AlertDescription>
        </Alert>

        <Alert v-else-if="note.state === 'too_large'">
            <AlertDescription>
                This file is larger than 1 MB, so it isn&#8217;t previewed. Open
                it in another editor.
            </AlertDescription>
        </Alert>

        <Alert v-else-if="note.state === 'unreadable'">
            <AlertDescription>
                MDVault couldn&#8217;t read this file. Close any programs that
                may be locking it and try again.
            </AlertDescription>
        </Alert>

        <footer
            class="mt-auto flex items-center gap-3 text-xs text-muted-foreground"
        >
            <span>{{ formatBytes(note.file_size) }}</span>
            <span :title="note.file_hash">
                SHA-256 {{ note.file_hash.slice(0, 12) }}&hellip;
            </span>
            <span>Editing will be available in a later update.</span>
        </footer>
    </article>
</template>
