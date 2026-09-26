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
                Saved for a future editor update.
            </p>

            <Button type="submit" :disabled="form.processing">Save</Button>
        </form>
    </div>
</template>
