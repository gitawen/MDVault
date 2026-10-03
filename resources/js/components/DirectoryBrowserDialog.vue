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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
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
    }>(),
    {
        initialPath: null,
        title: 'Choose folder',
        description: 'Browse and select a folder on the system.',
    },
);

const emit = defineEmits<{
    select: [path: string];
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

const filteredDirectories = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) {
        return directories.value;
    }
    return directories.value.filter((d) => d.name.toLowerCase().includes(q));
});

function fetchDirectory(path: string | null = null) {
    loading.value = true;
    error.value = null;

    useHttp<{ path?: string | null }, DirectoryBrowseResult>({ path })
        .post(browse.url(), {
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
        });
}

watch(open, (isOpen) => {
    if (isOpen) {
        fetchDirectory(props.initialPath);
    }
});

function navigateTo(path: string) {
    fetchDirectory(path);
}

function selectDirectoryItem(item: DirectoryItem) {
    selectedPath.value = item.path;
}

function enterDirectory(item: DirectoryItem) {
    fetchDirectory(item.path);
}

function confirmSelection() {
    if (selectedPath.value) {
        emit('select', selectedPath.value);
        open.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-2xl max-h-[85vh] flex flex-col p-4 sm:p-6 overflow-hidden">
            <DialogHeader class="pb-2">
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>

            <!-- Quick Links & Drives Bar -->
            <div class="flex flex-wrap items-center gap-1.5 pb-2 border-b border-border/50 text-xs">
                <span class="text-muted-foreground mr-1 text-[11px] font-medium uppercase tracking-wider">
                    Quick:
                </span>

                <button
                    v-for="link in quickLinks"
                    :key="link.path"
                    type="button"
                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-secondary/60 hover:bg-secondary text-secondary-foreground transition-colors font-medium cursor-pointer"
                    :class="{ 'ring-1 ring-primary/40 bg-secondary': currentPath === link.path }"
                    @click="navigateTo(link.path)"
                >
                    <component
                        :is="link.icon === 'vault' ? HardDrive : link.icon === 'home' ? Home : Folder"
                        class="size-3.5 text-muted-foreground"
                    />
                    {{ link.name }}
                </button>

                <div v-if="drives.length > 0" class="h-3 w-px bg-border mx-1" />

                <button
                    v-for="drive in drives"
                    :key="drive.path"
                    type="button"
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-muted hover:bg-muted/80 text-foreground transition-colors font-mono font-medium cursor-pointer text-xs"
                    :class="{ 'ring-1 ring-primary bg-primary/10 text-primary': currentPath.startsWith(drive.path) }"
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
                    class="size-8 p-0 shrink-0"
                    :disabled="!parentPath || loading"
                    title="Go to parent directory"
                    @click="parentPath && navigateTo(parentPath)"
                >
                    <ArrowUp class="size-4" />
                </Button>

                <div
                    class="flex items-center gap-1 overflow-x-auto no-scrollbar py-1 px-2.5 rounded-md bg-muted/40 border border-border/60 text-xs flex-1 min-w-0"
                >
                    <template v-for="(crumb, idx) in breadcrumbs" :key="crumb.path">
                        <button
                            type="button"
                            class="hover:underline font-mono text-muted-foreground hover:text-foreground shrink-0 cursor-pointer"
                            :class="{ 'font-semibold text-foreground': idx === breadcrumbs.length - 1 }"
                            @click="navigateTo(crumb.path)"
                        >
                            {{ crumb.name }}
                        </button>
                        <ChevronRight
                            v-if="idx < breadcrumbs.length - 1"
                            class="size-3 text-muted-foreground/60 shrink-0"
                        />
                    </template>
                </div>
            </div>

            <!-- Search filter -->
            <div class="relative mb-1">
                <Search class="absolute left-2.5 top-2.5 size-3.5 text-muted-foreground" />
                <Input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Filter folders..."
                    class="pl-8 h-8 text-xs bg-card"
                />
            </div>

            <!-- Folder list view -->
            <div
                class="flex-1 min-h-[220px] max-h-[300px] overflow-y-auto rounded-md border border-border/60 bg-card p-1.5"
            >
                <div v-if="loading" class="flex flex-col items-center justify-center h-48 gap-2 text-muted-foreground text-sm">
                    <Loader2 class="size-6 animate-spin text-primary" />
                    <span>Loading folders...</span>
                </div>

                <div v-else-if="error" class="flex items-center justify-center h-48 text-destructive text-sm">
                    {{ error }}
                </div>

                <div
                    v-else-if="filteredDirectories.length === 0"
                    class="flex flex-col items-center justify-center h-48 text-muted-foreground text-xs gap-1"
                >
                    <FolderOpen class="size-8 stroke-1 text-muted-foreground/40 mb-1" />
                    <span>{{ searchQuery ? 'No matching folders found.' : 'No subfolders in this directory.' }}</span>
                </div>

                <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-1">
                    <div
                        v-for="dir in filteredDirectories"
                        :key="dir.path"
                        class="flex items-center justify-between group px-2.5 py-1.5 rounded-md text-xs cursor-pointer select-none transition-colors border border-transparent"
                        :class="[
                            selectedPath === dir.path
                                ? 'bg-primary/10 border-primary/30 text-primary font-medium'
                                : 'hover:bg-muted/70 text-foreground'
                        ]"
                        @click="selectDirectoryItem(dir)"
                        @dblclick="enterDirectory(dir)"
                    >
                        <div class="flex items-center gap-2 min-w-0 flex-1 mr-1">
                            <Folder
                                class="size-4 shrink-0"
                                :class="selectedPath === dir.path ? 'text-primary fill-primary/20' : 'text-amber-500 fill-amber-500/20'"
                            />
                            <span class="truncate font-sans">{{ dir.name }}</span>
                        </div>

                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="size-6 p-0 opacity-40 group-hover:opacity-100 shrink-0 hover:bg-background/80"
                            title="Open folder"
                            @click.stop="enterDirectory(dir)"
                        >
                            <ChevronRight class="size-3.5" />
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Selection preview & action footer -->
            <div class="pt-2 text-xs flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-t border-border/50">
                <div class="min-w-0 flex-1">
                    <div class="text-[11px] text-muted-foreground">Target path:</div>
                    <div class="font-mono text-xs truncate text-foreground font-medium" :title="selectedPath">
                        {{ selectedPath || currentPath }}
                    </div>
                </div>

                <div class="flex items-center gap-1.5 self-end sm:self-auto shrink-0">
                    <Badge v-if="!isWritable" variant="destructive" class="text-[10px]">
                        Read-only
                    </Badge>

                    <Button type="button" variant="outline" size="sm" @click="open = false">
                        Cancel
                    </Button>

                    <Button
                        type="button"
                        size="sm"
                        :disabled="!selectedPath && !currentPath"
                        @click="confirmSelection"
                    >
                        Select folder
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
