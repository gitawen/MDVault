<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import StatusBar from '@/components/StatusBar.vue';
import TiptapEditor from '@/components/editor/TiptapEditor.vue';
import { workspace } from '@/routes';
import type { EditorPreferences, SystemStatus } from '@/types';

defineProps<{
    status: SystemStatus;
    editor: EditorPreferences;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspace', href: workspace() }],
    },
});

const demoContent = `
    <h1>Welcome to MDVault</h1>
    <p>This is a preview editor. Changes here are not saved yet.</p>
    <ul>
        <li>Local-first notes, stored on your device</li>
        <li>Rich-text editing powered by Tiptap</li>
        <li>Markdown and saving arrive in a later phase</li>
    </ul>
`;
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <Head title="Workspace" />

        <main class="flex-1 overflow-auto p-4">
            <TiptapEditor :content="demoContent" :preferences="editor" />
        </main>

        <StatusBar :status="status" />
    </div>
</template>
