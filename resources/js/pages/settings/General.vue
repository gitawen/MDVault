<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
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

        <Heading
            variant="small"
            title="General"
            description="Application behaviour and information."
        />

        <form class="space-y-2" @submit.prevent="save">
            <div class="flex items-center gap-2">
                <Checkbox
                    id="check_external_changes"
                    :model-value="form.check_external_changes"
                    @update:model-value="onCheckExternalChangesChange"
                />
                <Label for="check_external_changes">
                    Detect changes made outside MDVault
                </Label>
            </div>
            <p class="text-sm text-muted-foreground">
                When on, MDVault checks the open vault for changes made by other
                programs whenever its window is focused and every few seconds
                while it is visible. Opening and saving a note always checks the
                file, even when this is off.
            </p>
        </form>

        <Card>
            <CardHeader>
                <CardTitle>About</CardTitle>
                <CardDescription>{{ status.application }}</CardDescription>
            </CardHeader>
            <CardContent class="space-y-1 text-sm text-muted-foreground">
                <p>Version: {{ status.version }}</p>
                <p>
                    Runtime:
                    {{ status.runtime === 'desktop' ? 'Desktop' : 'Browser' }}
                </p>
                <p>Database: {{ status.database.driver }}</p>
            </CardContent>
        </Card>
    </div>
</template>
