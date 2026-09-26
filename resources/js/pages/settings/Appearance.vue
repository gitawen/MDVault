<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/settings/appearance';
import type { Appearance } from '@/types';

defineProps<{
    theme: Appearance;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Appearance settings',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <div class="space-y-6">
        <Head title="Appearance settings" />

        <h1 class="sr-only">Appearance settings</h1>

        <Heading
            variant="small"
            title="Appearance settings"
            description="Choose how MDVault looks on this device."
        />
        <AppearanceTabs />
    </div>
</template>
