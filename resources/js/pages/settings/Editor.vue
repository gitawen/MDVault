<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit, update } from '@/routes/settings/editor';
import type { EditorPreferences } from '@/types';

const props = defineProps<{
    preferences: EditorPreferences;
    defaultNewNoteTemplate: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Editor settings',
                href: edit(),
            },
        ],
    },
});

const form = useForm<EditorPreferences>({ ...props.preferences });

const fontFamilies = [
    { value: 'sans', label: 'Sans' },
    { value: 'serif', label: 'Serif' },
    { value: 'mono', label: 'Monospace' },
] as const;

function save() {
    form.patch(update.url(), { preserveScroll: true });
}

function resetTemplate() {
    form.new_note_template = props.defaultNewNoteTemplate;
}

// Kept as script constants (not inline template string literals): Vue's
// template compiler treats a literal "{{" inside a mustache interpolation
// as the start of a *nested* interpolation, so writing the placeholder text
// directly in the template breaks parsing.
const titlePlaceholder = '{{title}}';
const datePlaceholder = '{{date}}';
</script>

<template>
    <div class="space-y-6">
        <Head title="Editor settings" />

        <h1 class="sr-only">Editor settings</h1>

        <Heading
            variant="small"
            title="Editor"
            description="Customise how notes look while editing."
        />

        <form class="space-y-6" @submit.prevent="save">
            <div class="grid gap-2">
                <Label for="font_size">Font size</Label>
                <Input
                    id="font_size"
                    v-model.number="form.font_size"
                    type="number"
                    min="12"
                    max="24"
                    class="w-32"
                />
                <InputError :message="form.errors.font_size" />
            </div>

            <div class="grid gap-2">
                <Label for="font_family">Font family</Label>
                <Select v-model="form.font_family">
                    <SelectTrigger id="font_family" class="w-48">
                        <SelectValue placeholder="Select a font" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in fontFamilies"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.font_family" />
            </div>

            <div class="grid gap-2">
                <Label for="line_height">Line height</Label>
                <Input
                    id="line_height"
                    v-model.number="form.line_height"
                    type="number"
                    step="0.1"
                    min="1.2"
                    max="2.2"
                    class="w-32"
                />
                <InputError :message="form.errors.line_height" />
            </div>

            <div class="grid gap-2">
                <Label for="indent_size">Indent size</Label>
                <Input
                    id="indent_size"
                    v-model.number="form.indent_size"
                    type="number"
                    min="1"
                    max="8"
                    class="w-32"
                />
                <p class="text-xs text-muted-foreground">
                    Number of spaces inserted per Tab indent (default: 4).
                </p>
                <InputError :message="form.errors.indent_size" />
            </div>

            <div class="flex items-center gap-2">
                <Checkbox id="word_wrap" v-model="form.word_wrap" />
                <Label for="word_wrap">Wrap long lines</Label>
                <InputError :message="form.errors.word_wrap" />
            </div>

            <div class="flex items-center gap-2">
                <Checkbox
                    id="show_line_numbers"
                    v-model="form.show_line_numbers"
                />
                <Label for="show_line_numbers">Show line numbers</Label>
                <InputError :message="form.errors.show_line_numbers" />
            </div>
            <p class="-mt-4 text-sm text-muted-foreground">
                Not used by the editor yet.
            </p>

            <Heading
                variant="small"
                title="New notes"
                description="A frontmatter template MDVault adds when it creates a note."
            />

            <div class="flex items-center gap-2">
                <Checkbox
                    id="new_note_template_enabled"
                    v-model="form.new_note_template_enabled"
                />
                <Label for="new_note_template_enabled">
                    Add frontmatter to new notes
                </Label>
                <InputError :message="form.errors.new_note_template_enabled" />
            </div>

            <div class="grid gap-2">
                <Label for="new_note_template">Template</Label>
                <textarea
                    id="new_note_template"
                    v-model="form.new_note_template"
                    :disabled="!form.new_note_template_enabled"
                    spellcheck="false"
                    rows="4"
                    class="w-full max-w-xl resize-y rounded-md border bg-card p-2 font-mono text-sm outline-none disabled:opacity-50"
                />
                <InputError :message="form.errors.new_note_template" />
                <p class="text-sm text-muted-foreground">
                    Written between --- lines at the top of notes MDVault
                    creates. {{ titlePlaceholder }} becomes the note&#8217;s
                    name (quoted for you, so don&#8217;t add quotes).
                    {{ datePlaceholder }} becomes today&#8217;s date
                    (YYYY-MM-DD). Existing notes are never changed.
                </p>
                <div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="resetTemplate"
                    >
                        Reset to default
                    </Button>
                </div>
            </div>

            <Button type="submit" :disabled="form.processing">Save</Button>
        </form>
    </div>
</template>
