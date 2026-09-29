<script setup lang="ts">
import type { Editor } from '@tiptap/core';
import {
    Bold,
    Code,
    Heading1,
    Heading2,
    Heading3,
    Italic,
    Link as LinkIcon,
    List,
    ListOrdered,
    ListTodo,
    MoreHorizontal,
    Minus,
    Pilcrow,
    Quote,
    Redo2,
    RemoveFormatting,
    SquareCode,
    Strikethrough,
    Undo2,
} from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import LinkDialog from '@/components/editor/LinkDialog.vue';
import {
    toolbarCommands,
    type ToolbarCommandId,
} from '@/lib/editor/toolbarCommands';
import { useEditorTick } from '@/lib/editor/useEditorTick';

const props = defineProps<{
    editor: Editor | undefined;
}>();

const tick = useEditorTick(() => props.editor);

function isActive(id: ToolbarCommandId): boolean {
    void tick.value;

    return props.editor ? toolbarCommands[id].isActive(props.editor) : false;
}

function canRun(id: ToolbarCommandId): boolean {
    void tick.value;

    return props.editor ? toolbarCommands[id].canRun(props.editor) : false;
}

function run(id: ToolbarCommandId): void {
    if (props.editor) {
        toolbarCommands[id].run(props.editor);
    }
}

function title(id: ToolbarCommandId): string {
    const command = toolbarCommands[id];

    return command.shortcut
        ? `${command.label} (${command.shortcut})`
        : command.label;
}

/**
 * The overflow menu (below `md`) holds the less-used commands; the icon
 * buttons above stay visible at every width. `md:hidden`/`md:inline-flex`
 * (in the template) switch between the two, not JS, so this is purely a
 * data table for the dropdown's contents.
 */
const overflowCommandIcons: Record<ToolbarCommandId, unknown> = {
    undo: Undo2,
    redo: Redo2,
    bold: Bold,
    italic: Italic,
    strike: Strikethrough,
    code: Code,
    paragraph: Pilcrow,
    h1: Heading1,
    h2: Heading2,
    h3: Heading3,
    bulletList: List,
    orderedList: ListOrdered,
    taskList: ListTodo,
    blockquote: Quote,
    codeBlock: SquareCode,
    horizontalRule: Minus,
    clearFormatting: RemoveFormatting,
};

const overflowCommands: ToolbarCommandId[] = [
    'paragraph',
    'strike',
    'code',
    'taskList',
    'blockquote',
    'codeBlock',
    'horizontalRule',
    'clearFormatting',
];

const linkDialogOpen = ref(false);

function openLinkDialog(): void {
    linkDialogOpen.value = true;
}

function currentHref(): string {
    return props.editor ? (props.editor.getAttributes('link').href ?? '') : '';
}
</script>

<template>
    <div
        role="toolbar"
        aria-label="Formatting"
        class="sticky top-0 z-10 flex shrink-0 flex-wrap items-center gap-0.5 border-b bg-card p-1"
    >
        <Button
            variant="ghost"
            size="icon-sm"
            :aria-pressed="isActive('undo')"
            :disabled="!canRun('undo')"
            :title="title('undo')"
            @click="run('undo')"
        >
            <Undo2 />
        </Button>
        <Button
            variant="ghost"
            size="icon-sm"
            :aria-pressed="isActive('redo')"
            :disabled="!canRun('redo')"
            :title="title('redo')"
            @click="run('redo')"
        >
            <Redo2 />
        </Button>

        <Separator orientation="vertical" class="mx-1 hidden h-6 sm:block" />

        <Button
            variant="ghost"
            size="icon-sm"
            :aria-pressed="isActive('bold')"
            :disabled="!canRun('bold')"
            :title="title('bold')"
            @click="run('bold')"
        >
            <Bold />
        </Button>
        <Button
            variant="ghost"
            size="icon-sm"
            :aria-pressed="isActive('italic')"
            :disabled="!canRun('italic')"
            :title="title('italic')"
            @click="run('italic')"
        >
            <Italic />
        </Button>

        <Separator orientation="vertical" class="mx-1 hidden h-6 sm:block" />

        <Button
            variant="ghost"
            size="icon-sm"
            :aria-pressed="isActive('h1')"
            :disabled="!canRun('h1')"
            :title="title('h1')"
            @click="run('h1')"
        >
            <Heading1 />
        </Button>
        <Button
            variant="ghost"
            size="icon-sm"
            :aria-pressed="isActive('h2')"
            :disabled="!canRun('h2')"
            :title="title('h2')"
            @click="run('h2')"
        >
            <Heading2 />
        </Button>
        <Button
            variant="ghost"
            size="icon-sm"
            :aria-pressed="isActive('h3')"
            :disabled="!canRun('h3')"
            :title="title('h3')"
            @click="run('h3')"
        >
            <Heading3 />
        </Button>

        <Separator orientation="vertical" class="mx-1 hidden h-6 sm:block" />

        <Button
            variant="ghost"
            size="icon-sm"
            :aria-pressed="isActive('bulletList')"
            :disabled="!canRun('bulletList')"
            :title="title('bulletList')"
            @click="run('bulletList')"
        >
            <List />
        </Button>
        <Button
            variant="ghost"
            size="icon-sm"
            :aria-pressed="isActive('orderedList')"
            :disabled="!canRun('orderedList')"
            :title="title('orderedList')"
            @click="run('orderedList')"
        >
            <ListOrdered />
        </Button>

        <Separator orientation="vertical" class="mx-1 hidden h-6 sm:block" />

        <Button
            variant="ghost"
            size="icon-sm"
            :aria-pressed="false"
            :disabled="!editor"
            title="Link (Ctrl+K)"
            @click="openLinkDialog"
        >
            <LinkIcon />
        </Button>

        <!-- The less-used commands stay inline from `md` up, and collapse
             into this "More" menu below `md` so the toolbar never wraps
             onto enough rows to overlap the content. -->
        <template v-for="id in overflowCommands" :key="id">
            <Separator
                v-if="id === 'paragraph' || id === 'codeBlock'"
                orientation="vertical"
                class="mx-1 hidden h-6 md:block"
            />
            <Button
                variant="ghost"
                size="icon-sm"
                class="hidden md:inline-flex"
                :aria-pressed="isActive(id)"
                :disabled="!canRun(id)"
                :title="title(id)"
                @click="run(id)"
            >
                <component :is="overflowCommandIcons[id]" />
            </Button>
        </template>

        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    class="md:hidden"
                    title="More formatting"
                    aria-label="More formatting"
                >
                    <MoreHorizontal />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
                <DropdownMenuCheckboxItem
                    v-for="id in overflowCommands"
                    :key="id"
                    :checked="isActive(id)"
                    :disabled="!canRun(id)"
                    :title="title(id)"
                    @click="run(id)"
                >
                    <component
                        :is="overflowCommandIcons[id]"
                        class="mr-2 size-4"
                    />
                    {{ toolbarCommands[id].label }}
                </DropdownMenuCheckboxItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <LinkDialog
            v-model:open="linkDialogOpen"
            :editor="editor"
            :initial-href="currentHref()"
        />
    </div>
</template>
