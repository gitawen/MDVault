<script setup lang="ts">
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
            :disabled="readonly"
            @click="addFrontmatter"
        >
            Add frontmatter
        </Button>

        <Collapsible
            v-else
            v-model:open="open"
            class="rounded-md border p-2 text-sm"
        >
            <CollapsibleTrigger as-child>
                <button
                    type="button"
                    class="cursor-pointer font-medium text-muted-foreground"
                >
                    Frontmatter
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent class="mt-2 flex flex-col gap-2">
                <textarea
                    :value="value"
                    :readonly="readonly"
                    spellcheck="false"
                    rows="4"
                    class="w-full resize-y rounded-md border bg-card p-2 font-mono text-xs outline-none"
                    aria-label="Frontmatter YAML"
                    @input="onInput"
                />
                <InputError :message="problem ?? undefined" />
                <div>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="readonly"
                        @click="removeConfirmOpen = true"
                    >
                        Remove frontmatter
                    </Button>
                </div>
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
