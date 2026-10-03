<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    ALargeSmall,
    Check,
    FileCode,
    FileText,
    PenLine,
    RotateCcw,
    SlidersHorizontal,
    Sparkles,
} from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
    { value: 'sans', label: 'Sans (Clean & Modern)' },
    { value: 'serif', label: 'Serif (Editorial & Classic)' },
    { value: 'mono', label: 'Monospace (Code & Technical)' },
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

const previewStyle = computed(() => ({
    fontFamily:
        form.font_family === 'serif'
            ? 'ui-serif, Georgia, Cambria, "Times New Roman", Times, serif'
            : form.font_family === 'mono'
              ? 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace'
              : 'Instrument Sans, ui-sans-serif, system-ui, sans-serif',
    fontSize: `${form.font_size}px`,
    lineHeight: String(form.line_height),
}));
</script>

<template>
    <div class="space-y-6">
        <Head title="Editor settings" />

        <h1 class="sr-only">Editor settings</h1>

        <!-- Section Header -->
        <div class="space-y-1">
            <h2 class="text-xl font-semibold tracking-tight">Editor</h2>
            <p class="text-sm text-muted-foreground">
                Fine-tune note typography, indentation, reading layouts, and frontmatter templates.
            </p>
        </div>

        <form class="space-y-6" @submit.prevent="save">
            <!-- Typography Card with Interactive Live Preview -->
            <Card class="border-border/60 shadow-xs">
                <CardHeader class="pb-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <ALargeSmall class="h-4 w-4" />
                            </div>
                            <div>
                                <CardTitle class="text-base font-medium">Typography & Spacing</CardTitle>
                                <CardDescription>
                                    Control readability and text proportions while drafting notes.
                                </CardDescription>
                            </div>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-6">
                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
                        <!-- Controls Column -->
                        <div class="space-y-4 lg:col-span-6">
                            <!-- Font Family -->
                            <div class="grid gap-2">
                                <Label for="font_family" class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    Font Family
                                </Label>
                                <Select v-model="form.font_family">
                                    <SelectTrigger id="font_family" class="w-full bg-background">
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

                            <div class="grid grid-cols-2 gap-3">
                                <!-- Font Size -->
                                <div class="grid gap-2">
                                    <Label for="font_size" class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Font Size (px)
                                    </Label>
                                    <div class="relative">
                                        <Input
                                            id="font_size"
                                            v-model.number="form.font_size"
                                            type="number"
                                            min="12"
                                            max="24"
                                            class="w-full bg-background pr-8"
                                        />
                                        <span class="pointer-events-none absolute right-2.5 top-2.5 text-xs text-muted-foreground font-mono">
                                            px
                                        </span>
                                    </div>
                                    <InputError :message="form.errors.font_size" />
                                </div>

                                <!-- Line Height -->
                                <div class="grid gap-2">
                                    <Label for="line_height" class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Line Height
                                    </Label>
                                    <div class="relative">
                                        <Input
                                            id="line_height"
                                            v-model.number="form.line_height"
                                            type="number"
                                            step="0.1"
                                            min="1.2"
                                            max="2.2"
                                            class="w-full bg-background pr-6"
                                        />
                                        <span class="pointer-events-none absolute right-2.5 top-2.5 text-xs text-muted-foreground font-mono">
                                            ×
                                        </span>
                                    </div>
                                    <InputError :message="form.errors.line_height" />
                                </div>
                            </div>

                            <!-- Indent Size -->
                            <div class="grid gap-2">
                                <Label for="indent_size" class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    Tab Indent Width
                                </Label>
                                <div class="relative">
                                    <Input
                                        id="indent_size"
                                        v-model.number="form.indent_size"
                                        type="number"
                                        min="1"
                                        max="8"
                                        class="w-full bg-background pr-16"
                                    />
                                    <span class="pointer-events-none absolute right-2.5 top-2.5 text-xs text-muted-foreground">
                                        spaces
                                    </span>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    Number of spaces inserted per Tab indent (default: 4).
                                </p>
                                <InputError :message="form.errors.indent_size" />
                            </div>
                        </div>

                        <!-- Live Preview Column -->
                        <div class="flex flex-col rounded-xl border border-border/60 bg-muted/20 p-4 lg:col-span-6">
                            <div class="mb-3 flex items-center justify-between border-b border-border/40 pb-2">
                                <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    <Sparkles class="h-3.5 w-3.5 text-primary" />
                                    <span>Interactive Preview</span>
                                </div>
                                <span class="rounded-full bg-background px-2 py-0.5 text-[11px] font-mono text-muted-foreground border">
                                    {{ form.font_family }} · {{ form.font_size }}px · {{ form.line_height }}×
                                </span>
                            </div>
                            <div
                                class="flex-1 rounded-lg border border-border/50 bg-card p-4 shadow-2xs transition-all overflow-hidden"
                                :style="previewStyle"
                            >
                                <h3 class="font-bold tracking-tight text-foreground mb-1.5" :style="{ fontSize: `${Math.round(form.font_size * 1.25)}px` }">
                                    Morning Reflection
                                </h3>
                                <p class="text-foreground/90">
                                    Markdown vaults store ideas in plain text. Adjust typography until your notes feel effortless to draft and read.
                                </p>
                                <p class="mt-2 text-muted-foreground text-xs font-mono">
                                    &bull; Quick capture &nbsp;&bull; Instant search
                                </p>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Editor Behaviors Card -->
            <Card class="border-border/60 shadow-xs">
                <CardHeader class="pb-3">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <SlidersHorizontal class="h-4 w-4" />
                        </div>
                        <div>
                            <CardTitle class="text-base font-medium">Editor View & Layout</CardTitle>
                            <CardDescription>
                                Toggle viewport layout and visual aids.
                            </CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-3">
                    <!-- Word Wrap Toggle -->
                    <div
                        class="flex items-start gap-3.5 rounded-lg border border-border/50 bg-muted/20 p-4 transition-colors hover:bg-muted/30"
                    >
                        <Checkbox
                            id="word_wrap"
                            class="mt-0.5"
                            :model-value="form.word_wrap"
                            @update:model-value="(val) => (form.word_wrap = val === true)"
                        />
                        <div class="space-y-1">
                            <Label
                                for="word_wrap"
                                class="text-sm font-medium leading-none cursor-pointer text-foreground"
                            >
                                Wrap long lines
                            </Label>
                            <p class="text-xs leading-relaxed text-muted-foreground">
                                Automatically wraps paragraphs to fit the editor's visible area, avoiding horizontal scrolling.
                            </p>
                            <InputError :message="form.errors.word_wrap" />
                        </div>
                    </div>

                    <!-- Line Numbers Toggle -->
                    <div
                        class="flex items-start justify-between gap-3.5 rounded-lg border border-border/50 bg-muted/20 p-4 transition-colors hover:bg-muted/30"
                    >
                        <div class="flex items-start gap-3.5">
                            <Checkbox
                                id="show_line_numbers"
                                class="mt-0.5"
                                :model-value="form.show_line_numbers"
                                @update:model-value="(val) => (form.show_line_numbers = val === true)"
                            />
                            <div class="space-y-1">
                                <Label
                                    for="show_line_numbers"
                                    class="text-sm font-medium leading-none cursor-pointer text-foreground"
                                >
                                    Show line numbers
                                </Label>
                                <p class="text-xs leading-relaxed text-muted-foreground">
                                    Display line count indicators in the left margin gutter.
                                </p>
                                <InputError :message="form.errors.show_line_numbers" />
                            </div>
                        </div>
                        <Badge variant="outline" class="text-[10px] text-muted-foreground shrink-0">
                            Upcoming
                        </Badge>
                    </div>
                </CardContent>
            </Card>

            <!-- Frontmatter Template Card -->
            <Card class="border-border/60 shadow-xs">
                <CardHeader class="pb-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <FileCode class="h-4 w-4" />
                            </div>
                            <div>
                                <CardTitle class="text-base font-medium">New Note Template (Frontmatter)</CardTitle>
                                <CardDescription>
                                    Initial YAML metadata automatically prepended to new documents.
                                </CardDescription>
                            </div>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <!-- Template Enabled Switch -->
                    <div
                        class="flex items-start gap-3.5 rounded-lg border border-border/50 bg-muted/20 p-4 transition-colors hover:bg-muted/30"
                    >
                        <Checkbox
                            id="new_note_template_enabled"
                            class="mt-0.5"
                            :model-value="form.new_note_template_enabled"
                            @update:model-value="(val) => (form.new_note_template_enabled = val === true)"
                        />
                        <div class="space-y-1">
                            <Label
                                for="new_note_template_enabled"
                                class="text-sm font-medium leading-none cursor-pointer text-foreground"
                            >
                                Add frontmatter to new notes
                            </Label>
                            <p class="text-xs leading-relaxed text-muted-foreground">
                                When enabled, MDVault writes YAML frontmatter between <code class="font-mono text-foreground font-semibold">---</code> markers at the top of every newly created note.
                            </p>
                            <InputError :message="form.errors.new_note_template_enabled" />
                        </div>
                    </div>

                    <!-- Code Area -->
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <Label for="new_note_template" class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                Frontmatter YAML
                            </Label>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="h-7 text-xs gap-1.5 text-muted-foreground hover:text-foreground"
                                @click="resetTemplate"
                            >
                                <RotateCcw class="h-3 w-3" />
                                Reset to default
                            </Button>
                        </div>
                        <div class="relative">
                            <textarea
                                id="new_note_template"
                                v-model="form.new_note_template"
                                :disabled="!form.new_note_template_enabled"
                                spellcheck="false"
                                rows="5"
                                class="w-full resize-y rounded-lg border border-input bg-card p-3 font-mono text-sm leading-relaxed outline-none transition-colors focus-visible:border-ring focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                            />
                        </div>
                        <InputError :message="form.errors.new_note_template" />

                        <!-- Dynamic Variable Tags -->
                        <div class="rounded-lg border border-border/50 bg-muted/20 p-3 text-xs text-muted-foreground">
                            <div class="flex flex-wrap items-center gap-1.5 mb-1.5">
                                <span class="font-medium text-foreground">Available Variables:</span>
                                <span class="rounded bg-background px-1.5 py-0.5 font-mono text-foreground border shadow-2xs">
                                    {{ titlePlaceholder }}
                                </span>
                                <span>Note name (auto-quoted)</span>
                                <span class="mx-1 text-muted-foreground/40">&bull;</span>
                                <span class="rounded bg-background px-1.5 py-0.5 font-mono text-foreground border shadow-2xs">
                                    {{ datePlaceholder }}
                                </span>
                                <span>Today's date (YYYY-MM-DD)</span>
                            </div>
                            <p class="text-[11px] text-muted-foreground/80">
                                Existing notes and imported files are never retroactively modified.
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Submit Action Bar -->
            <div class="flex items-center justify-between border-t border-border/60 pt-4">
                <p class="text-xs text-muted-foreground">
                    Preferences are saved immediately to your active configuration.
                </p>
                <Button type="submit" :disabled="form.processing" class="min-w-28 gap-1.5">
                    <Check v-if="!form.processing" class="h-4 w-4" />
                    <span>Save Changes</span>
                </Button>
            </div>
        </form>
    </div>
</template>
