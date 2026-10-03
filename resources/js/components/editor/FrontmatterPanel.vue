<script setup lang="ts">
import { ChevronDown, FileCode2, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { frontmatterProblem } from '@/lib/editor/richContent';

const { readonly = false } = defineProps<{
    readonly?: boolean;
}>();

const emit = defineEmits<{
    change: [];
}>();

const value = defineModel<string | null>({ default: null });

// Open by default whenever there is a non-empty block to show; an
// added-but-still-empty block starts open too (see addFrontmatter below).
const open = ref(value.value !== null && value.value !== '');

function addFrontmatter(): void {
    value.value = '';
    open.value = true;
    emit('change');
}

function onInput(event: Event): void {
    value.value = (event.target as HTMLTextAreaElement).value;
    emit('change');
}

const problem = computed(() =>
    value.value !== null ? frontmatterProblem(value.value) : null,
);

const removeConfirmOpen = ref(false);

function confirmRemove(): void {
    removeConfirmOpen.value = false;
    value.value = null;
    emit('change');
}
</script>

<template>
    <div>
        <Button
            v-if="value === null"
            type="button"
            variant="ghost"
            size="sm"
            class="h-7 px-2 text-xs text-muted-foreground hover:text-foreground gap-1.5"
            :disabled="readonly"
            @click="addFrontmatter"
        >
            <Plus class="size-3.5" />
            Add frontmatter
        </Button>

        <Collapsible
            v-else
            v-model:open="open"
            class="rounded-lg border border-border/80 bg-muted/20 p-2.5 text-sm transition-all"
        >
            <div class="flex items-center justify-between">
                <CollapsibleTrigger as-child>
                    <button
                        type="button"
                        class="flex items-center gap-1.5 cursor-pointer font-medium text-xs text-muted-foreground hover:text-foreground transition-colors select-none"
                    >
                        <ChevronDown
                            class="size-3.5 transition-transform duration-200"
                            :class="{ '-rotate-90': !open }"
                        />
                        <FileCode2 class="size-3.5 text-primary/70" />
                        <span>Frontmatter (YAML metadata)</span>
                    </button>
                </CollapsibleTrigger>
                <Button
                    v-if="open"
                    type="button"
                    size="sm"
                    variant="ghost"
                    class="h-6 px-2 text-xs text-destructive hover:text-destructive hover:bg-destructive/10"
                    :disabled="readonly"
                    @click="removeConfirmOpen = true"
                >
                    Remove
                </Button>
            </div>
            <CollapsibleContent class="mt-2.5 flex flex-col gap-2">
                <textarea
                    :value="value"
                    :readonly="readonly"
                    spellcheck="false"
                    rows="4"
                    class="w-full resize-y rounded-md border border-border/80 bg-card p-2.5 font-mono text-xs leading-relaxed outline-none focus:border-primary/40 focus:ring-1 focus:ring-primary/20"
                    aria-label="Frontmatter YAML"
                    placeholder="key: value"
                    @input="onInput"
                />
                <InputError :message="problem ?? undefined" />
            </CollapsibleContent>
        </Collapsible>

        <Dialog v-model:open="removeConfirmOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Remove frontmatter?</DialogTitle>
                    <DialogDescription>
                        Remove the frontmatter block from this note?
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        @click="removeConfirmOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button variant="destructive" @click="confirmRemove">
                        Remove
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
