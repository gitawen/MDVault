<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, FileText, Folder, MoreHorizontal } from '@lucide/vue';
import { inject } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { show } from '@/routes/notes';
import type { NoteTreeNode } from '@/types';
import { noteTreeActionsKey } from './noteTreeActions';

const props = defineProps<{
    node: NoteTreeNode;
    selectedUuid: string | null;
    canTrash: boolean;
    depth: number;
}>();

const actions = inject(noteTreeActionsKey);
const indent = `${props.depth * 1.25}rem`;
</script>

<template>
    <div>
        <Collapsible
            v-if="node.type === 'folder'"
            v-slot="{ open: isOpen }"
            :default-open="node.open"
        >
            <div
                class="group flex items-center gap-1 rounded-md pr-1 hover:bg-accent"
                :style="{ paddingLeft: indent }"
            >
                <CollapsibleTrigger as-child>
                    <button
                        type="button"
                        class="flex flex-1 items-center gap-1.5 rounded-md py-1.5 text-left text-sm"
                        :aria-label="`Toggle folder ${node.name}`"
                    >
                        <ChevronRight
                            class="size-4 shrink-0 text-muted-foreground transition-transform"
                            :class="{ 'rotate-90': isOpen }"
                        />
                        <Folder class="size-4 shrink-0 text-muted-foreground" />
                        <span class="truncate">{{ node.name }}</span>
                    </button>
                </CollapsibleTrigger>

                <DropdownMenu v-if="actions">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-6 opacity-0 group-hover:opacity-100"
                            aria-label="Folder actions"
                        >
                            <MoreHorizontal class="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem @click="actions.newNote(node.path)">
                            New note here
                        </DropdownMenuItem>
                        <DropdownMenuItem @click="actions.newFolder(node.path)">
                            New folder here
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            variant="destructive"
                            @click="actions.removeFolder(node)"
                        >
                            Delete folder
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <CollapsibleContent>
                <NoteTreeItem
                    v-for="child in node.children"
                    :key="child.type === 'folder' ? child.path : child.uuid"
                    :node="child"
                    :selected-uuid="selectedUuid"
                    :can-trash="canTrash"
                    :depth="depth + 1"
                />
            </CollapsibleContent>
        </Collapsible>

        <div
            v-else
            class="group flex items-center gap-1 rounded-md pr-1 hover:bg-accent"
            :class="{ 'bg-accent': node.uuid === selectedUuid }"
            :style="{ paddingLeft: indent }"
        >
            <Link
                :href="show(node.uuid)"
                :only="['note']"
                preserve-state
                preserve-scroll
                class="flex flex-1 items-center gap-1.5 py-1.5 text-sm"
            >
                <FileText class="size-4 shrink-0 text-muted-foreground" />
                <span class="truncate">{{ node.title }}</span>
            </Link>

            <DropdownMenu v-if="actions">
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-6 opacity-0 group-hover:opacity-100"
                        aria-label="Note actions"
                    >
                        <MoreHorizontal class="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem @click="actions.rename(node)">
                        Rename
                    </DropdownMenuItem>
                    <DropdownMenuItem @click="actions.move(node)">
                        Move to&hellip;
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        variant="destructive"
                        @click="actions.remove(node)"
                    >
                        Delete
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </div>
</template>
