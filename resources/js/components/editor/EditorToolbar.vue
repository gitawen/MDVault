<script setup lang="ts">
import type { EditorView } from '@codemirror/view';
import {
    Bold,
    Code,
    Code2,
    Columns2,
    Eye,
    Heading1,
    Heading2,
    Heading3,
    Italic,
    Link as LinkIcon,
    List,
    ListOrdered,
    ListTodo,
    Minus,
    MoreHorizontal,
    Pilcrow,
    Quote,
    Redo2,
    RemoveFormatting,
    SquareCode,
    Strikethrough,
    Table as TableIcon,
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
import { linkAt } from '@/lib/editor/codemirrorCommands';
import {
    toolbarCommands,
    type ToolbarCommandId,
} from '@/lib/editor/toolbarCommands';

const props = defineProps<{
    view?: EditorView | undefined;
    displayMode?: 'code' | 'split' | 'preview';
    /**
     * Bumped by `SourceEditor.vue`'s CodeMirror `onUpdate` callback on every
     * doc/selection/focus change — the sole reactivity source for the
     * toolbar's active/enabled state (FR-01/FR-02).
     */
    revision?: number;
}>();

const emit = defineEmits<{
    'update:displayMode': [mode: 'code' | 'split' | 'preview'];
}>();

function isActive(id: ToolbarCommandId): boolean {
    void props.revision;

    return props.view ? toolbarCommands[id].isActive(props.view) : false;
}

function canRun(id: ToolbarCommandId): boolean {
    void props.revision;

    return props.view ? toolbarCommands[id].canRun(props.view) : false;
}

function run(id: ToolbarCommandId): void {
    if (props.view) {
        toolbarCommands[id].run(props.view);
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
 * buttons above stay visible at every width.
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
    table: TableIcon,
};

const overflowCommands: ToolbarCommandId[] = [
    'paragraph',
    'strike',
    'code',
    'taskList',
    'blockquote',
    'codeBlock',
    'horizontalRule',
    'table',
    'clearFormatting',
];

const linkDialogOpen = ref(false);

function openLinkDialog(): void {
    linkDialogOpen.value = true;
}

function currentHref(): string {
    const view = props.view;
    if (!view) {
        return '';
    }
    return linkAt(view.state, view.state.selection.main.head)?.href ?? '';
}

defineExpose({ openLinkDialog });
</script>

<template>
    <div
        role="toolbar"
        aria-label="Formatting"
        class="no-scrollbar sticky top-0 z-10 flex shrink-0 touch-pan-x items-center gap-1.5 overflow-x-auto border-b border-border/70 bg-card/95 px-2 py-1.5 backdrop-blur-xs"
    >
        <!-- History Group -->
        <div
            class="flex shrink-0 items-center gap-0.5 rounded-md border border-border/40 bg-muted/40 p-0.5"
        >
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 rounded-sm transition-all sm:size-7.5"
                :class="
                    isActive('undo')
                        ? 'bg-background text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
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
                class="size-7 rounded-sm transition-all sm:size-7.5"
                :class="
                    isActive('redo')
                        ? 'bg-background text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
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
        <div
            class="flex shrink-0 items-center gap-0.5 rounded-md border border-border/40 bg-muted/40 p-0.5"
        >
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 rounded-sm transition-all sm:size-7.5"
                :class="
                    isActive('bold')
                        ? 'bg-background font-semibold text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
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
                class="size-7 rounded-sm transition-all sm:size-7.5"
                :class="
                    isActive('italic')
                        ? 'bg-background font-semibold text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
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
                class="hidden size-7 rounded-sm transition-all sm:inline-flex sm:size-7.5"
                :class="
                    isActive('strike')
                        ? 'bg-background font-semibold text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
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
                class="hidden size-7 rounded-sm transition-all sm:inline-flex sm:size-7.5"
                :class="
                    isActive('code')
                        ? 'bg-background font-semibold text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
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
        <div
            class="flex shrink-0 items-center gap-0.5 rounded-md border border-border/40 bg-muted/40 p-0.5"
        >
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 rounded-sm transition-all sm:size-7.5"
                :class="
                    isActive('h1')
                        ? 'bg-background font-semibold text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
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
                class="size-7 rounded-sm transition-all sm:size-7.5"
                :class="
                    isActive('h2')
                        ? 'bg-background font-semibold text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
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
                class="hidden size-7 rounded-sm transition-all sm:inline-flex sm:size-7.5"
                :class="
                    isActive('h3')
                        ? 'bg-background font-semibold text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
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
        <div
            class="flex shrink-0 items-center gap-0.5 rounded-md border border-border/40 bg-muted/40 p-0.5"
        >
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 rounded-sm transition-all sm:size-7.5"
                :class="
                    isActive('bulletList')
                        ? 'bg-background text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
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
                class="size-7 rounded-sm transition-all sm:size-7.5"
                :class="
                    isActive('orderedList')
                        ? 'bg-background text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
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
                class="hidden size-7 rounded-sm transition-all sm:inline-flex sm:size-7.5"
                :class="
                    isActive('taskList')
                        ? 'bg-background text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
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
        <div
            class="flex shrink-0 items-center gap-0.5 rounded-md border border-border/40 bg-muted/40 p-0.5"
        >
            <Button
                variant="ghost"
                size="icon-sm"
                class="size-7 rounded-sm text-muted-foreground transition-all hover:text-foreground sm:size-7.5"
                :aria-pressed="false"
                :disabled="!props.view"
                title="Link (Ctrl+K)"
                @click="openLinkDialog"
            >
                <LinkIcon class="size-3.5 sm:size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                class="hidden size-7 rounded-sm transition-all sm:size-7.5 md:inline-flex"
                :class="
                    isActive('blockquote')
                        ? 'bg-background text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
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
                class="hidden size-7 rounded-sm transition-all sm:size-7.5 md:inline-flex"
                :class="
                    isActive('codeBlock')
                        ? 'bg-background text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
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
                class="hidden size-7 rounded-sm transition-all sm:size-7.5 md:inline-flex"
                :class="
                    isActive('horizontalRule')
                        ? 'bg-background text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
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
                class="hidden size-7 rounded-sm transition-all sm:size-7.5 md:inline-flex"
                :class="
                    isActive('clearFormatting')
                        ? 'bg-background text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
                :aria-pressed="isActive('clearFormatting')"
                :disabled="!canRun('clearFormatting')"
                :title="title('clearFormatting')"
                @click="run('clearFormatting')"
            >
                <RemoveFormatting class="size-3.5 sm:size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                class="hidden size-7 rounded-sm transition-all sm:size-7.5 md:inline-flex"
                :class="
                    isActive('table')
                        ? 'bg-background text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
                :aria-pressed="isActive('table')"
                :disabled="!canRun('table')"
                :title="title('table')"
                @click="run('table')"
            >
                <TableIcon class="size-3.5 sm:size-4" />
            </Button>
        </div>

        <!-- Overflow Dropdown Menu for Mobile & Tablet -->
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    class="size-7 shrink-0 rounded-sm text-muted-foreground hover:text-foreground sm:size-7.5 md:hidden"
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

        <!-- View Mode Segmented Control (Editor / Split / Preview) -->
        <div
            v-if="displayMode"
            class="ml-auto flex shrink-0 items-center gap-0.5 rounded-md border border-border/40 bg-muted/40 p-0.5"
        >
            <Button
                variant="ghost"
                size="sm"
                class="h-7 cursor-pointer rounded-sm px-2 text-xs transition-all sm:px-2.5"
                :class="
                    displayMode === 'code'
                        ? 'bg-background font-semibold text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
                title="Editor only"
                @click="emit('update:displayMode', 'code')"
            >
                <Code2 class="size-3.5" />
                <span class="hidden sm:inline">Editor</span>
            </Button>
            <Button
                variant="ghost"
                size="sm"
                class="hidden h-7 cursor-pointer rounded-sm px-2 text-xs transition-all sm:inline-flex sm:px-2.5"
                :class="
                    displayMode === 'split'
                        ? 'bg-background font-semibold text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
                title="Side-by-side Editor and Live Preview"
                @click="emit('update:displayMode', 'split')"
            >
                <Columns2 class="size-3.5" />
                <span class="hidden md:inline">Split</span>
            </Button>
            <Button
                variant="ghost"
                size="sm"
                class="h-7 cursor-pointer rounded-sm px-2 text-xs transition-all sm:px-2.5"
                :class="
                    displayMode === 'preview'
                        ? 'bg-background font-semibold text-foreground shadow-xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
                title="Rendered Markdown Preview only"
                @click="emit('update:displayMode', 'preview')"
            >
                <Eye class="size-3.5" />
                <span class="hidden sm:inline">Preview</span>
            </Button>
        </div>

        <LinkDialog
            v-model:open="linkDialogOpen"
            :view="props.view"
            :initial-href="currentHref()"
        />
    </div>
</template>
