<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Info, Palette, Sparkles } from '@lucide/vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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

        <!-- Section Header -->
        <div class="space-y-1">
            <h2 class="text-xl font-semibold tracking-tight">Appearance</h2>
            <p class="text-sm text-muted-foreground">
                Customize how MDVault looks on this device with tailored light, dark, or system themes.
            </p>
        </div>

        <!-- Theme Selection Section -->
        <Card class="border-border/60 shadow-xs">
            <CardHeader class="pb-4">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <Palette class="h-4 w-4" />
                    </div>
                    <div>
                        <CardTitle class="text-base font-medium">Interface Theme</CardTitle>
                        <CardDescription>
                            Select your visual palette. Changes take effect across the entire application immediately.
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <AppearanceTabs />
            </CardContent>
        </Card>

        <!-- Information Callout -->
        <div class="flex items-start gap-3 rounded-xl border border-border/50 bg-muted/20 p-4 text-sm text-muted-foreground">
            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                <Info class="h-3.5 w-3.5" />
            </div>
            <p class="text-xs leading-relaxed">
                Theme preferences are saved to your browser/desktop local storage on this computer.
                When choosing <span class="font-medium text-foreground">System</span>, MDVault responds dynamically whenever your operating system switches between daylight and dark modes.
            </p>
        </div>
    </div>
</template>
