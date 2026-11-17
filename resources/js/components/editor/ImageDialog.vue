<script setup lang="ts">
import type { EditorView } from '@codemirror/view';
import {
    AlertCircle,
    ClipboardPaste,
    Image as ImageIcon,
    Link2,
    Lock,
    Upload,
    X,
} from '@lucide/vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { insertImage } from '@/lib/editor/codemirrorCommands';
import { isAllowedHref } from '@/lib/editor/linkProtocols';
import {
    ALLOWED_IMAGE_MIME_TYPES,
    deriveAltText,
    uploadVaultMedia,
    validateImageFile,
} from '@/lib/editor/mediaUpload';
import { formatBytes } from '@/lib/formatBytes';

type DialogMode = 'upload' | 'url';

const props = defineProps<{
    view?: EditorView | undefined;
    vaultUuid?: string;
    vaultEncrypted?: boolean;
    initialSrc?: string;
    initialAlt?: string;
    range?: { from: number; to: number } | undefined;
}>();

const emit = defineEmits<{
    apply: [src: string];
}>();

const open = defineModel<boolean>('open', { default: false });

const mode = ref<DialogMode>('upload');
const altText = ref('');
const imageUrl = ref('');
const selectedFile = ref<File | null>(null);
const previewObjectUrl = ref<string | null>(null);
const fileInputRef = ref<HTMLInputElement | null>(null);
const isDragOver = ref(false);
const isUploading = ref(false);
const error = ref<string | null>(null);
const urlPreviewFailed = ref(false);

const isEditingExisting = computed(() => props.range !== undefined);
const trimmedUrl = computed(() => imageUrl.value.trim());
const isUrlValid = computed(() =>
    trimmedUrl.value === '' ? false : isAllowedHref(trimmedUrl.value),
);

const canSubmit = computed(() => {
    if (isUploading.value) {
        return false;
    }

    if (mode.value === 'upload') {
        return (
            !props.vaultEncrypted &&
            Boolean(props.vaultUuid) &&
            selectedFile.value !== null
        );
    }

    return trimmedUrl.value !== '';
});

function revokePreviewUrl(): void {
    if (previewObjectUrl.value) {
        URL.revokeObjectURL(previewObjectUrl.value);
        previewObjectUrl.value = null;
    }
}

function clearSelectedFile(): void {
    revokePreviewUrl();
    selectedFile.value = null;
    if (fileInputRef.value) {
        fileInputRef.value.value = '';
    }
}

function selectFile(file: File): void {
    error.value = null;
    const validationError = validateImageFile(file);

    if (validationError !== null) {
        error.value = validationError;
        return;
    }

    clearSelectedFile();
    selectedFile.value = file;
    previewObjectUrl.value = URL.createObjectURL(file);

    if (altText.value.trim() === '') {
        altText.value = deriveAltText(file);
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        const existingSrc = props.initialSrc?.trim() ?? '';
        mode.value =
            props.vaultEncrypted || existingSrc !== '' ? 'url' : 'upload';
        imageUrl.value = existingSrc;
        altText.value = props.initialAlt ?? '';
        error.value = null;
        urlPreviewFailed.value = false;
        isDragOver.value = false;
        clearSelectedFile();
    } else {
        clearSelectedFile();
    }
});

watch(imageUrl, () => {
    urlPreviewFailed.value = false;
    if (mode.value === 'url') {
        error.value = null;
    }
});

watch(mode, () => {
    error.value = null;
});

onUnmounted(() => {
    revokePreviewUrl();
});

function openFilePicker(): void {
    if (props.vaultEncrypted || isUploading.value) {
        return;
    }
    fileInputRef.value?.click();
}

function onFileInputChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (file) {
        selectFile(file);
    }
}

function onDropzoneDrop(event: DragEvent): void {
    isDragOver.value = false;
    if (props.vaultEncrypted || isUploading.value) {
        return;
    }

    const file = event.dataTransfer?.files?.[0];
    if (file) {
        selectFile(file);
    }
}

function handleDialogPaste(event: ClipboardEvent): void {
    if (mode.value !== 'upload' || props.vaultEncrypted || isUploading.value) {
        return;
    }

    const plainText = event.clipboardData?.getData('text/plain') ?? '';
    if (plainText !== '') {
        return;
    }

    const items = event.clipboardData?.items;
    if (items) {
        for (const item of Array.from(items)) {
            if (item.kind === 'file' && item.type.startsWith('image/')) {
                const file = item.getAsFile();
                if (file) {
                    event.preventDefault();
                    selectFile(file);
                    return;
                }
            }
        }
    }

    const file = event.clipboardData?.files?.[0];
    if (file) {
        event.preventDefault();
        selectFile(file);
    }
}

const MIME_TO_EXT: Record<string, string> = {
    'image/png': 'png',
    'image/jpeg': 'jpg',
    'image/gif': 'gif',
    'image/webp': 'webp',
};

async function pasteFromClipboard(): Promise<void> {
    if (props.vaultEncrypted || isUploading.value) {
        return;
    }

    error.value = null;

    if (
        typeof navigator === 'undefined' ||
        !navigator.clipboard ||
        typeof navigator.clipboard.read !== 'function'
    ) {
        error.value =
            "Clipboard image reading isn't available here. Press Ctrl+V or choose a file instead.";
        return;
    }

    try {
        const clipboardItems = await navigator.clipboard.read();
        for (const item of clipboardItems) {
            const matchedMime = item.types.find((type) =>
                ALLOWED_IMAGE_MIME_TYPES.has(type.toLowerCase()),
            );
            if (matchedMime) {
                const blob = await item.getType(matchedMime);
                const ext = MIME_TO_EXT[matchedMime.toLowerCase()] ?? 'png';
                const file = new File([blob], `screenshot.${ext}`, {
                    type: matchedMime,
                });
                selectFile(file);
                return;
            }
        }

        error.value = 'No supported image found on the clipboard.';
    } catch {
        error.value =
            'Clipboard access was denied. Press Ctrl+V inside the dialog or choose a file instead.';
    }
}

async function apply(): Promise<void> {
    const active = props.view;
    error.value = null;

    if (!active) {
        open.value = false;
        return;
    }

    if (mode.value === 'url') {
        const url = trimmedUrl.value;
        if (!isAllowedHref(url)) {
            error.value = "That image URL isn't allowed.";
            return;
        }

        insertImage(active, url, altText.value, props.range);
        emit('apply', url);
        open.value = false;
        return;
    }

    if (props.vaultEncrypted) {
        error.value =
            "Image attachments aren't supported in encrypted vaults yet.";
        return;
    }

    if (!props.vaultUuid || !selectedFile.value) {
        error.value = 'Choose or paste an image to attach.';
        return;
    }

    isUploading.value = true;
    try {
        const outcome = await uploadVaultMedia(
            props.vaultUuid,
            selectedFile.value,
        );

        if (outcome.kind === 'error') {
            error.value = outcome.message;
            return;
        }

        insertImage(active, outcome.media.url, altText.value, props.range);
        emit('apply', outcome.media.url);
        open.value = false;
    } finally {
        isUploading.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" @paste="handleDialogPaste">
            <DialogHeader>
                <DialogTitle>
                    {{ isEditingExisting ? 'Edit Image' : 'Insert Image' }}
                </DialogTitle>
                <DialogDescription>
                    Upload or paste a raster image into your shared Media
                    folder, or insert an external image URL.
                </DialogDescription>
            </DialogHeader>

            <!-- Mode Toggle -->
            <div
                class="grid grid-cols-2 gap-1 rounded-lg border border-border/60 bg-muted/40 p-1"
                role="tablist"
                aria-label="Image source mode"
            >
                <button
                    type="button"
                    role="tab"
                    :aria-selected="mode === 'upload'"
                    class="flex items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-all"
                    :class="
                        mode === 'upload'
                            ? 'bg-background text-foreground shadow-xs'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :disabled="isUploading"
                    @click="mode = 'upload'"
                >
                    <Upload class="size-3.5" />
                    <span>Upload / Paste</span>
                </button>
                <button
                    type="button"
                    role="tab"
                    :aria-selected="mode === 'url'"
                    class="flex items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-all"
                    :class="
                        mode === 'url'
                            ? 'bg-background text-foreground shadow-xs'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :disabled="isUploading"
                    @click="mode = 'url'"
                >
                    <Link2 class="size-3.5" />
                    <span>Image URL</span>
                </button>
            </div>

            <!-- Upload / Paste Tab -->
            <div v-if="mode === 'upload'" class="grid gap-3">
                <Alert v-if="vaultEncrypted">
                    <Lock class="size-4" />
                    <AlertDescription>
                        Image attachments aren&#8217;t supported in encrypted
                        vaults yet. You can still insert an external image URL
                        using the Image URL tab.
                    </AlertDescription>
                </Alert>

                <input
                    ref="fileInputRef"
                    type="file"
                    accept="image/png,image/jpeg,image/gif,image/webp"
                    class="hidden"
                    @change="onFileInputChange"
                />

                <!-- Selected File Preview -->
                <div
                    v-if="selectedFile && previewObjectUrl"
                    class="relative overflow-hidden rounded-lg border border-border/70 bg-muted/20 p-3"
                >
                    <div
                        class="flex items-center justify-center overflow-hidden rounded-md bg-background/80 p-2"
                    >
                        <img
                            :src="previewObjectUrl"
                            :alt="altText || selectedFile.name"
                            class="max-h-48 max-w-full rounded object-contain"
                        />
                    </div>
                    <div
                        class="mt-2.5 flex items-center justify-between gap-2 text-xs"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-medium text-foreground">
                                {{ selectedFile.name }}
                            </p>
                            <p class="text-muted-foreground">
                                {{ formatBytes(selectedFile.size) }}
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="h-7 shrink-0 gap-1 px-2 text-xs"
                            :disabled="isUploading"
                            @click="clearSelectedFile"
                        >
                            <X class="size-3.5" />
                            <span>Clear</span>
                        </Button>
                    </div>
                </div>

                <!-- Dropzone -->
                <div
                    v-else
                    class="flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed px-4 py-6 text-center transition-colors"
                    :class="[
                        vaultEncrypted
                            ? 'cursor-not-allowed border-border/50 bg-muted/20 opacity-60'
                            : isDragOver
                              ? 'border-primary bg-primary/5'
                              : 'border-border/80 bg-muted/20 hover:border-border',
                    ]"
                    @dragover.prevent="if (!vaultEncrypted) isDragOver = true;"
                    @dragenter.prevent="if (!vaultEncrypted) isDragOver = true;"
                    @dragleave="isDragOver = false"
                    @drop.prevent="onDropzoneDrop"
                >
                    <div
                        class="flex size-10 items-center justify-center rounded-full bg-muted text-muted-foreground"
                    >
                        <ImageIcon class="size-5" />
                    </div>
                    <div class="space-y-0.5 text-xs">
                        <p class="font-medium text-foreground">
                            Drop an image here, or press Ctrl+V to paste
                        </p>
                        <p class="text-muted-foreground">
                            PNG, JPEG, GIF, or WEBP up to 10 MB
                        </p>
                    </div>
                    <div
                        class="mt-1 flex flex-wrap items-center justify-center gap-2"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="h-8 gap-1.5 text-xs"
                            :disabled="vaultEncrypted || isUploading"
                            @click="openFilePicker"
                        >
                            <Upload class="size-3.5" />
                            <span>Choose image</span>
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="h-8 gap-1.5 text-xs"
                            :disabled="vaultEncrypted || isUploading"
                            @click="pasteFromClipboard"
                        >
                            <ClipboardPaste class="size-3.5" />
                            <span>Paste from clipboard</span>
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Image URL Tab -->
            <div v-else class="grid gap-3">
                <div class="grid gap-1.5">
                    <Label for="image-url-input">Image URL</Label>
                    <Input
                        id="image-url-input"
                        v-model="imageUrl"
                        type="text"
                        placeholder="https://example.com/diagram.png or /media/..."
                        :disabled="isUploading"
                        @keydown.enter.prevent="apply"
                    />
                </div>

                <div
                    v-if="trimmedUrl && isUrlValid && !urlPreviewFailed"
                    class="flex items-center justify-center overflow-hidden rounded-lg border border-border/70 bg-muted/20 p-3"
                >
                    <img
                        :src="trimmedUrl"
                        :alt="altText || 'Image preview'"
                        class="max-h-44 max-w-full rounded object-contain"
                        @error="urlPreviewFailed = true"
                        @load="urlPreviewFailed = false"
                    />
                </div>
            </div>

            <!-- Shared Alt Text Field -->
            <div class="grid gap-1.5">
                <Label for="image-alt-input">Alt text</Label>
                <Input
                    id="image-alt-input"
                    v-model="altText"
                    type="text"
                    placeholder="Describe the image (optional)"
                    :disabled="isUploading"
                    @keydown.enter.prevent="apply"
                />
            </div>

            <div
                v-if="error"
                class="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-destructive"
                role="alert"
            >
                <AlertCircle class="mt-0.5 size-3.5 shrink-0" />
                <span>{{ error }}</span>
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="ghost"
                    :disabled="isUploading"
                    @click="open = false"
                >
                    Cancel
                </Button>
                <Button type="button" :disabled="!canSubmit" @click="apply">
                    <Spinner v-if="isUploading" class="mr-1.5 size-3.5" />
                    {{
                        isUploading
                            ? 'Uploading…'
                            : isEditingExisting
                              ? 'Update Image'
                              : 'Insert Image'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
