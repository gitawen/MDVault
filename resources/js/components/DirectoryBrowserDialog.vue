<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import {
    ArrowUp,
    ChevronRight,
    Folder,
    FolderOpen,
    HardDrive,
    Home,
    Loader2,
    Search,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { browse } from '@/routes/directories';
import type { DirectoryBrowseResult, DirectoryItem } from '@/types';

const props = withDefaults(
    defineProps<{
        initialPath?: string | null;
        title?: string;
        description?: string;
        multiple?: boolean;
    }>(),
    {
        initialPath: null,
        title: 'Choose folder',
        description: 'Browse and select a folder on the system.',
        multiple: false,
    },
);

const emit = defineEmits<{
    select: [path: string];
    'select-multiple': [paths: string[]];
}>();

const open = defineModel<boolean>('open', { default: false });

const currentPath = ref<string>('');
const parentPath = ref<string | null>(null);
const breadcrumbs = ref<Array<{ name: string; path: string }>>([]);
const drives = ref<Array<{ name: string; path: string }>>([]);
const quickLinks = ref<Array<{ name: string; path: string; icon: string }>>([]);
const directories = ref<DirectoryItem[]>([]);
const isWritable = ref<boolean>(true);
const searchQuery = ref<string>('');
const loading = ref<boolean>(false);
const error = ref<string | null>(null);
const selectedPath = ref<string>('');
const selectedPaths = ref<string[]>([]);

const filteredDirectories = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) {
        return directories.value;
    }
    return directories.value.filter((d) => d.name.toLowerCase().includes(q));
});

const isAllInFolderSelected = computed(() => {
    if (filteredDirectories.value.length === 0) {
        return false;
    }
    return filteredDirectories.value.every((d) =>
        selectedPaths.value.includes(d.path),
    );
});

function fetchDirectory(path: string | null = null) {
    loading.value = true;
    error.value = null;

    useHttp<{ path?: string | null }, DirectoryBrowseResult>({ path }).post(
        browse.url(),
        {
            onSuccess: (data) => {
                currentPath.value = data.current_path;
                parentPath.value = data.parent_path;
                breadcrumbs.value = data.breadcrumbs;
                drives.value = data.drives;
                quickLinks.value = data.quick_links;
                directories.value = data.directories;
                isWritable.value = data.is_writable;
                selectedPath.value = data.current_path;
                searchQuery.value = '';
            },
            onError: () => {
                error.value = 'Failed to load directory contents.';
            },
            onFinish: () => {
                loading.value = false;
            },
        },
    );
}

watch(open, (isOpen) => {
    if (isOpen) {
        selectedPaths.value = [];
        selectedPath.value = '';
        fetchDirectory(props.initialPath);
    }
});

function navigateTo(path: string) {
    fetchDirectory(path);
}

function selectDirectoryItem(item: DirectoryItem) {
    selectedPath.value = item.path;
}

function toggleSelection(path: string) {
    const idx = selectedPaths.value.indexOf(path);
    if (idx === -1) {
        selectedPaths.value.push(path);
    } else {
        selectedPaths.value.splice(idx, 1);
    }
}

function handleRowClick(dir: DirectoryItem) {
    if (props.multiple) {
        toggleSelection(dir.path);
    } else {
        selectDirectoryItem(dir);
    }
}

function selectAllInFolder() {
    const visiblePaths = filteredDirectories.value.map((d) => d.path);
    if (isAllInFolderSelected.value) {
        selectedPaths.value = selectedPaths.value.filter(
            (p) => !visiblePaths.includes(p),
        );
    } else {
        const combined = new Set([...selectedPaths.value, ...visiblePaths]);
        selectedPaths.value = Array.from(combined);
    }
}

function clearSelection() {
    selectedPaths.value = [];
}

function enterDirectory(item: DirectoryItem) {
    fetchDirectory(item.path);
}

function confirmSelection() {
    if (props.multiple) {
        const finalPaths =
            selectedPaths.value.length > 0
                ? [...selectedPaths.value]
                : [currentPath.value];
        if (finalPaths.length > 0 && finalPaths[0]) {
            emit('select-multiple', finalPaths);
            if (finalPaths.length === 1) {
                emit('select', finalPaths[0]);
            }
            open.value = false;
        }
    } else {
        if (selectedPath.value) {
            emit('select', selectedPath.value);
            open.value = false;
        }
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex max-h-[85vh] max-w-2xl flex-col overflow-hidden p-4 sm:p-6"
        >
            <DialogHeader class="pb-2">
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>

            <!-- Quick Links & Drives Bar -->
            <div
                class="flex flex-wrap items-center gap-1.5 border-b border-border/50 pb-2 text-xs"
            >
                <span
                    class="mr-1 text-[11px] font-medium tracking-wider text-muted-foreground uppercase"
                >
                    Quick:
                </span>

                <button
                    v-for="link in quickLinks"
                    :key="link.path"
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1 rounded-md bg-secondary/60 px-2.5 py-1 font-medium text-secondary-foreground transition-colors hover:bg-secondary"
                    :class="{
                        'bg-secondary ring-1 ring-primary/40':
                            currentPath === link.path,
                    }"
                    @click="navigateTo(link.path)"
                >
                    <component
                        :is="
                            link.icon === 'vault'
                                ? HardDrive
                                : link.icon === 'home'
                                  ? Home
                                  : Folder
                        "
                        class="size-3.5 text-muted-foreground"
                    />
                    {{ link.name }}
                </button>

                <div v-if="drives.length > 0" class="mx-1 h-3 w-px bg-border" />

                <button
                    v-for="drive in drives"
                    :key="drive.path"
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1 rounded bg-muted px-2 py-0.5 font-mono text-xs font-medium text-foreground transition-colors hover:bg-muted/80"
                    :class="{
                        'bg-primary/10 text-primary ring-1 ring-primary':
                            currentPath.startsWith(drive.path),
                    }"
                    @click="navigateTo(drive.path)"
                >
                    <HardDrive class="size-3 text-muted-foreground" />
                    {{ drive.name }}
                </button>
            </div>

            <!-- Path Breadcrumbs & Navigation -->
            <div class="flex items-center gap-2 py-1.5">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="size-8 shrink-0 p-0"
                    :disabled="!parentPath || loading"
                    title="Go to parent directory"
                    @click="parentPath && navigateTo(parentPath)"
                >
                    <ArrowUp class="size-4" />
                </Button>

                <div
                    class="no-scrollbar flex min-w-0 flex-1 items-center gap-1 overflow-x-auto rounded-md border border-border/60 bg-muted/40 px-2.5 py-1 text-xs"
                >
                    <template
                        v-for="(crumb, idx) in breadcrumbs"
                        :key="crumb.path"
                    >
                        <button
                            type="button"
                            class="shrink-0 cursor-pointer font-mono text-muted-foreground hover:text-foreground hover:underline"
                            :class="{
                                'font-semibold text-foreground':
                                    idx === breadcrumbs.length - 1,
                            }"
                            @click="navigateTo(crumb.path)"
                        >
                            {{ crumb.name }}
                        </button>
                        <ChevronRight
                            v-if="idx < breadcrumbs.length - 1"
                            class="size-3 shrink-0 text-muted-foreground/60"
                        />
                    </template>
                </div>
            </div>

            <!-- Search filter & Multi-select Toolbar -->
            <div class="space-y-1.5">
                <div class="relative">
                    <Search
                        class="absolute top-2.5 left-2.5 size-3.5 text-muted-foreground"
                    />
                    <Input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Filter folders..."
                        class="h-8 bg-card pl-8 text-xs"
                    />
                </div>

                <div
                    v-if="multiple"
                    class="flex items-center justify-between px-0.5 text-xs text-muted-foreground"
                >
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="cursor-pointer font-medium text-primary hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="filteredDirectories.length === 0"
                            @click="selectAllInFolder"
                        >
                            {{
                                isAllInFolderSelected
                                    ? 'Deselect all in folder'
                                    : 'Select all in folder'
                            }}
                        </button>
                        <span
                            v-if="filteredDirectories.length > 0"
                            class="text-[11px] text-muted-foreground"
                        >
                            ({{ filteredDirectories.length }} folder{{
                                filteredDirectories.length === 1 ? '' : 's'
                            }})
                        </span>
                    </div>

                    <button
                        v-if="selectedPaths.length > 0"
                        type="button"
                        class="cursor-pointer text-xs text-muted-foreground hover:text-foreground"
                        @click="clearSelection"
                    >
                        Clear ({{ selectedPaths.length }})
                    </button>
                </div>
            </div>

            <!-- Folder list view -->
            <div
                class="max-h-[300px] min-h-[220px] flex-1 overflow-y-auto rounded-md border border-border/60 bg-card p-1.5"
            >
                <div
                    v-if="loading"
                    class="flex h-48 flex-col items-center justify-center gap-2 text-sm text-muted-foreground"
                >
                    <Loader2 class="size-6 animate-spin text-primary" />
                    <span>Loading folders...</span>
                </div>

                <div
                    v-else-if="error"
                    class="flex h-48 items-center justify-center text-sm text-destructive"
                >
                    {{ error }}
                </div>

                <div
                    v-else-if="filteredDirectories.length === 0"
                    class="flex h-48 flex-col items-center justify-center gap-1 text-xs text-muted-foreground"
                >
                    <FolderOpen
                        class="mb-1 size-8 stroke-1 text-muted-foreground/40"
                    />
                    <span>{{
                        searchQuery
                            ? 'No matching folders found.'
                            : 'No subfolders in this directory.'
                    }}</span>
                </div>

                <div v-else class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                    <div
                        v-for="dir in filteredDirectories"
                        :key="dir.path"
                        class="group flex cursor-pointer items-center justify-between rounded-md border border-transparent px-2.5 py-1.5 text-xs transition-colors select-none"
                        :class="[
                            multiple
                                ? selectedPaths.includes(dir.path)
                                    ? 'border-primary/30 bg-primary/10 font-medium text-primary'
                                    : 'text-foreground hover:bg-muted/70'
                                : selectedPath === dir.path
                                  ? 'border-primary/30 bg-primary/10 font-medium text-primary'
                                  : 'text-foreground hover:bg-muted/70',
                        ]"
                        @click="handleRowClick(dir)"
                        @dblclick="enterDirectory(dir)"
                    >
                        <div
                            class="mr-1 flex min-w-0 flex-1 items-center gap-2"
                        >
                            <Checkbox
                                v-if="multiple"
                                :model-value="selectedPaths.includes(dir.path)"
                                class="mr-0.5"
                                @click.stop
                                @update:model-value="toggleSelection(dir.path)"
                            />
                            <Folder
                                class="size-4 shrink-0"
                                :class="
                                    (
                                        multiple
                                            ? selectedPaths.includes(dir.path)
                                            : selectedPath === dir.path
                                    )
                                        ? 'fill-primary/20 text-primary'
                                        : 'fill-amber-500/20 text-amber-500'
                                "
                            />
                            <span class="truncate font-sans">{{
                                dir.name
                            }}</span>
                        </div>

                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="size-6 shrink-0 p-0 opacity-40 group-hover:opacity-100 hover:bg-background/80"
                            title="Open folder"
                            @click.stop="enterDirectory(dir)"
                        >
                            <ChevronRight class="size-3.5" />
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Selection preview & action footer -->
            <div
                class="flex flex-col justify-between gap-2 border-t border-border/50 pt-2 text-xs sm:flex-row sm:items-center"
            >
                <div class="min-w-0 flex-1">
                    <template v-if="multiple">
                        <template v-if="selectedPaths.length === 0">
                            <div class="text-[11px] text-muted-foreground">
                                Target path:
                            </div>
                            <div
                                class="truncate font-mono text-xs font-medium text-foreground"
                                :title="currentPath"
                            >
                                {{ currentPath }}
                            </div>
                        </template>
                        <template v-else-if="selectedPaths.length === 1">
                            <div class="text-[11px] text-muted-foreground">
                                Selected 1 folder:
                            </div>
                            <div
                                class="truncate font-mono text-xs font-medium text-foreground"
                                :title="selectedPaths[0]"
                            >
                                {{ selectedPaths[0] }}
                            </div>
                        </template>
                        <template v-else>
                            <div class="flex items-center gap-1.5">
                                <span class="text-[11px] text-muted-foreground">
                                    Selected:
                                </span>
                                <Badge
                                    variant="secondary"
                                    class="font-mono text-[11px]"
                                >
                                    {{ selectedPaths.length }} folders
                                </Badge>
                            </div>
                            <div
                                class="mt-0.5 truncate font-mono text-xs text-muted-foreground"
                                :title="selectedPaths.join(', ')"
                            >
                                {{
                                    selectedPaths
                                        .map((p) =>
                                            p
                                                .split(/[\\/]/)
                                                .filter(Boolean)
                                                .pop(),
                                        )
                                        .join(', ')
                                }}
                            </div>
                        </template>
                    </template>
                    <template v-else>
                        <div class="text-[11px] text-muted-foreground">
                            Target path:
                        </div>
                        <div
                            class="truncate font-mono text-xs font-medium text-foreground"
                            :title="selectedPath"
                        >
                            {{ selectedPath || currentPath }}
                        </div>
                    </template>
                </div>

                <div
                    class="flex shrink-0 items-center gap-1.5 self-end sm:self-auto"
                >
                    <Badge
                        v-if="!isWritable"
                        variant="destructive"
                        class="text-[10px]"
                    >
                        Read-only
                    </Badge>

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="open = false"
                    >
                        Cancel
                    </Button>

                    <Button
                        type="button"
                        size="sm"
                        :disabled="
                            multiple
                                ? !selectedPaths.length && !currentPath
                                : !selectedPath && !currentPath
                        "
                        @click="confirmSelection"
                    >
                        {{
                            multiple
                                ? selectedPaths.length === 0
                                    ? 'Select folder'
                                    : selectedPaths.length === 1
                                      ? 'Select 1 folder'
                                      : `Select ${selectedPaths.length} folders`
                                : 'Select folder'
                        }}
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
