<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    Activity,
    CheckCircle2,
    Database,
    Globe,
    Monitor,
    RefreshCw,
    SlidersHorizontal,
    Sparkles,
    Tag,
} from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { edit, update } from '@/routes/settings/general';
import type { GeneralSettings, SystemStatus } from '@/types';

const props = defineProps<{
    settings: GeneralSettings;
    status: SystemStatus;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'General settings',
                href: edit(),
            },
        ],
    },
});

const form = useForm<GeneralSettings>({ ...props.settings });

function save() {
    form.patch(update.url(), { preserveScroll: true });
}

function onCheckExternalChangesChange(value: boolean | 'indeterminate') {
    form.check_external_changes = value === true;
    save();
}
</script>

<template>
    <div class="space-y-6">
        <Head title="General settings" />

        <h1 class="sr-only">General settings</h1>

        <!-- Section Header -->
        <div class="space-y-1">
            <h2 class="text-xl font-semibold tracking-tight">General</h2>
            <p class="text-sm text-muted-foreground">
                Manage file synchronization behavior and view application system information.
            </p>
        </div>

        <!-- External Changes Card -->
        <Card class="border-border/60 shadow-xs">
            <CardHeader class="pb-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <RefreshCw class="h-4 w-4" />
                        </div>
                        <div>
                            <CardTitle class="text-base font-medium">File Watcher & Synchronization</CardTitle>
                            <CardDescription>
                                Keep open notes in sync with changes made by outside editors.
                            </CardDescription>
                        </div>
                    </div>
                    <Badge
                        :variant="form.check_external_changes ? 'default' : 'secondary'"
                        class="hidden sm:inline-flex items-center gap-1 text-xs"
                    >
                        <span
                            class="h-1.5 w-1.5 rounded-full"
                            :class="form.check_external_changes ? 'bg-emerald-400 animate-pulse' : 'bg-muted-foreground'"
                        />
                        {{ form.check_external_changes ? 'Monitoring active' : 'Manual only' }}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent>
                <form class="space-y-4" @submit.prevent="save">
                    <div
                        class="flex items-start gap-3.5 rounded-lg border border-border/50 bg-muted/20 p-4 transition-colors hover:bg-muted/30"
                    >
                        <Checkbox
                            id="check_external_changes"
                            class="mt-0.5"
                            :model-value="form.check_external_changes"
                            @update:model-value="onCheckExternalChangesChange"
                        />
                        <div class="space-y-1">
                            <Label
                                for="check_external_changes"
                                class="text-sm font-medium leading-none cursor-pointer text-foreground"
                            >
                                Detect changes made outside MDVault
                            </Label>
                            <p class="text-xs leading-relaxed text-muted-foreground">
                                When enabled, MDVault checks the active vault for updates whenever this window gains focus,
                                as well as periodically in the background while visible. Opening and saving a note always
                                checks the underlying file regardless of this setting.
                            </p>
                        </div>
                    </div>
                </form>
            </CardContent>
        </Card>

        <!-- About System Information Card -->
        <Card class="border-border/60 shadow-xs">
            <CardHeader class="pb-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <Sparkles class="h-4 w-4" />
                        </div>
                        <div>
                            <CardTitle class="text-base font-medium">System Diagnostics</CardTitle>
                            <CardDescription>{{ status.application }}</CardDescription>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                        <CheckCircle2 class="h-3.5 w-3.5" />
                        <span>System Operational</span>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <!-- Version Stat -->
                    <div class="flex items-center gap-3 rounded-lg border border-border/50 bg-card p-3.5 shadow-2xs">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                            <Tag class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-muted-foreground">Version</p>
                            <p class="truncate font-mono text-sm font-medium text-foreground">
                                v{{ status.version }}
                            </p>
                        </div>
                    </div>

                    <!-- Runtime Stat -->
                    <div class="flex items-center gap-3 rounded-lg border border-border/50 bg-card p-3.5 shadow-2xs">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                            <component
                                :is="status.runtime === 'desktop' ? Monitor : Globe"
                                class="h-4 w-4"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-muted-foreground">Runtime</p>
                            <p class="truncate text-sm font-medium capitalize text-foreground">
                                {{ status.runtime === 'desktop' ? 'Desktop' : 'Browser' }}
                            </p>
                        </div>
                    </div>

                    <!-- Database Stat -->
                    <div class="flex items-center gap-3 rounded-lg border border-border/50 bg-card p-3.5 shadow-2xs">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                            <Database class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-muted-foreground">Database</p>
                            <p class="truncate text-sm font-medium uppercase text-foreground">
                                {{ status.database.driver }}
                            </p>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
