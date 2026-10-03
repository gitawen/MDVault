<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit, update } from '@/routes/settings/security';
import type { SecuritySettings } from '@/types';

const props = defineProps<{
    preferences: SecuritySettings;
    autoLockChoices: number[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Security settings',
                href: edit(),
            },
        ],
    },
});

// The Select works with strings; the server gets a number.
const form = useForm({
    auto_lock_minutes: String(props.preferences.auto_lock_minutes),
    lock_on_screen_lock: props.preferences.lock_on_screen_lock,
}).transform((data) => ({
    ...data,
    auto_lock_minutes: Number(data.auto_lock_minutes),
}));

function label(minutes: number): string {
    if (minutes === 0) {
        return 'Never';
    }

    return minutes === 1 ? '1 minute' : `${minutes} minutes`;
}

function save() {
    form.patch(update.url(), { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-6">
        <Head title="Security settings" />

        <h1 class="sr-only">Security settings</h1>

        <Heading
            variant="small"
            title="Security"
            description="When MDVault locks your encrypted vaults."
        />

        <form class="space-y-6" @submit.prevent="save">
            <div class="grid gap-2">
                <Label for="auto_lock_minutes">Lock after being idle for</Label>
                <Select v-model="form.auto_lock_minutes">
                    <SelectTrigger id="auto_lock_minutes" class="w-48">
                        <SelectValue placeholder="Select a time" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="minutes in autoLockChoices"
                            :key="minutes"
                            :value="String(minutes)"
                        >
                            {{ label(minutes) }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.auto_lock_minutes" />
                <p class="text-sm text-muted-foreground">
                    Unlocked vaults lock themselves when you haven't used
                    MDVault for this long. Closing or reloading the window, or
                    restarting MDVault, always locks every vault.
                </p>
            </div>

            <div class="space-y-1">
                <div class="flex items-center gap-2">
                    <Checkbox
                        id="lock_on_screen_lock"
                        v-model="form.lock_on_screen_lock"
                    />
                    <Label for="lock_on_screen_lock">
                        Lock vaults when the computer's screen locks
                    </Label>
                </div>
                <InputError :message="form.errors.lock_on_screen_lock" />
            </div>

            <Button type="submit" :disabled="form.processing">Save</Button>
        </form>
    </div>
</template>
