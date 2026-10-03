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
        class="sticky top-0 z-10 flex shrink-0 items-center gap-1.5 border-b border-border/70 bg-card/95 backdrop-blur-xs px-2 py-1.5 overflow-x-auto no-scrollbar touch-pan-x"
    >
        <!-- History Group -->
        <div class="flex shrink-0 items-center gap-0.5 rounded-md bg-muted/40 p-0.5 border border-border/40">
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 sm:size-7.5 rounded-sm transition-all"
                :class="isActive('undo') ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                :aria-pressed="isActive('undo')"
                :disabled="!canRun('undo')"
                :title="title('undo')"
                @click="run('undo')"
            >
                <Undo2 class="size-3.5 sm:size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 sm:size-7.5 rounded-sm transition-all"
                :class="isActive('redo') ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                :aria-pressed="isActive('redo')"
                :disabled="!canRun('redo')"
                :title="title('redo')"
                @click="run('redo')"
            >
                <Redo2 class="size-3.5 sm:size-4" />
            </Button>
        </div>

        <Separator orientation="vertical" class="h-5 shrink-0" />

        <!-- Inline Formatting Group -->
        <div class="flex shrink-0 items-center gap-0.5 rounded-md bg-muted/40 p-0.5 border border-border/40">
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 sm:size-7.5 rounded-sm transition-all"
                :class="isActive('bold') ? 'bg-background text-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                :aria-pressed="isActive('bold')"
                :disabled="!canRun('bold')"
                :title="title('bold')"
                @click="run('bold')"
            >
                <Bold class="size-3.5 sm:size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 sm:size-7.5 rounded-sm transition-all"
                :class="isActive('italic') ? 'bg-background text-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                :aria-pressed="isActive('italic')"
                :disabled="!canRun('italic')"
                :title="title('italic')"
                @click="run('italic')"
            >
                <Italic class="size-3.5 sm:size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 sm:size-7.5 rounded-sm transition-all hidden sm:inline-flex"
                :class="isActive('strike') ? 'bg-background text-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                :aria-pressed="isActive('strike')"
                :disabled="!canRun('strike')"
                :title="title('strike')"
                @click="run('strike')"
            >
                <Strikethrough class="size-3.5 sm:size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 sm:size-7.5 rounded-sm transition-all hidden sm:inline-flex"
                :class="isActive('code') ? 'bg-background text-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                :aria-pressed="isActive('code')"
                :disabled="!canRun('code')"
                :title="title('code')"
                @click="run('code')"
            >
                <Code class="size-3.5 sm:size-4" />
            </Button>
        </div>

        <Separator orientation="vertical" class="h-5 shrink-0" />

        <!-- Headings Group -->
        <div class="flex shrink-0 items-center gap-0.5 rounded-md bg-muted/40 p-0.5 border border-border/40">
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 sm:size-7.5 rounded-sm transition-all"
                :class="isActive('h1') ? 'bg-background text-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                :aria-pressed="isActive('h1')"
                :disabled="!canRun('h1')"
                :title="title('h1')"
                @click="run('h1')"
            >
                <Heading1 class="size-3.5 sm:size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 sm:size-7.5 rounded-sm transition-all"
                :class="isActive('h2') ? 'bg-background text-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                :aria-pressed="isActive('h2')"
                :disabled="!canRun('h2')"
                :title="title('h2')"
                @click="run('h2')"
            >
                <Heading2 class="size-3.5 sm:size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 sm:size-7.5 rounded-sm transition-all hidden sm:inline-flex"
                :class="isActive('h3') ? 'bg-background text-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                :aria-pressed="isActive('h3')"
                :disabled="!canRun('h3')"
                :title="title('h3')"
                @click="run('h3')"
            >
                <Heading3 class="size-3.5 sm:size-4" />
            </Button>
        </div>

        <Separator orientation="vertical" class="h-5 shrink-0" />

        <!-- Lists Group -->
        <div class="flex shrink-0 items-center gap-0.5 rounded-md bg-muted/40 p-0.5 border border-border/40">
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 sm:size-7.5 rounded-sm transition-all"
                :class="isActive('bulletList') ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                :aria-pressed="isActive('bulletList')"
                :disabled="!canRun('bulletList')"
                :title="title('bulletList')"
                @click="run('bulletList')"
            >
                <List class="size-3.5 sm:size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 sm:size-7.5 rounded-sm transition-all"
                :class="isActive('orderedList') ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                :aria-pressed="isActive('orderedList')"
                :disabled="!canRun('orderedList')"
                :title="title('orderedList')"
                @click="run('orderedList')"
            >
                <ListOrdered class="size-3.5 sm:size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 sm:size-7.5 rounded-sm transition-all hidden sm:inline-flex"
                :class="isActive('taskList') ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                :aria-pressed="isActive('taskList')"
                :disabled="!canRun('taskList')"
                :title="title('taskList')"
                @click="run('taskList')"
            >
                <ListTodo class="size-3.5 sm:size-4" />
            </Button>
        </div>

        <Separator orientation="vertical" class="h-5 shrink-0" />

        <!-- Links & Blocks Group -->
        <div class="flex shrink-0 items-center gap-0.5 rounded-md bg-muted/40 p-0.5 border border-border/40">
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 sm:size-7.5 rounded-sm transition-all text-muted-foreground hover:text-foreground"
                :aria-pressed="false"
                :disabled="!editor"
                title="Link (Ctrl+K)"
                @click="openLinkDialog"
            >
                <LinkIcon class="size-3.5 sm:size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 sm:size-7.5 rounded-sm transition-all hidden md:inline-flex"
                :class="isActive('blockquote') ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                :aria-pressed="isActive('blockquote')"
                :disabled="!canRun('blockquote')"
                :title="title('blockquote')"
                @click="run('blockquote')"
            >
                <Quote class="size-3.5 sm:size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 sm:size-7.5 rounded-sm transition-all hidden md:inline-flex"
                :class="isActive('codeBlock') ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                :aria-pressed="isActive('codeBlock')"
                :disabled="!canRun('codeBlock')"
                :title="title('codeBlock')"
                @click="run('codeBlock')"
            >
                <SquareCode class="size-3.5 sm:size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 sm:size-7.5 rounded-sm transition-all hidden md:inline-flex"
                :class="isActive('horizontalRule') ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                :aria-pressed="isActive('horizontalRule')"
                :disabled="!canRun('horizontalRule')"
                :title="title('horizontalRule')"
                @click="run('horizontalRule')"
            >
                <Minus class="size-3.5 sm:size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 sm:size-7.5 rounded-sm transition-all hidden md:inline-flex"
                :class="isActive('clearFormatting') ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                :aria-pressed="isActive('clearFormatting')"
                :disabled="!canRun('clearFormatting')"
                :title="title('clearFormatting')"
                @click="run('clearFormatting')"
            >
                <RemoveFormatting class="size-3.5 sm:size-4" />
            </Button>
        </div>

        <!-- Overflow Dropdown Menu for Mobile & Tablet -->
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    class="size-7 sm:size-7.5 shrink-0 rounded-sm md:hidden text-muted-foreground hover:text-foreground"
                    title="More formatting"
                    aria-label="More formatting"
                >
                    <MoreHorizontal class="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-48">
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
