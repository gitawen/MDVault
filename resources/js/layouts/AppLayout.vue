<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { syncAppearance } from '@/composables/useAppearance';
import { useAutoLock } from '@/composables/useAutoLock';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();

const page = usePage();

watch(
    () => page.props.appearance,
    (value) => {
        if (value) {
            syncAppearance(value);
        }
    },
    { immediate: true },
);

useAutoLock();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppLayout>
</template>
