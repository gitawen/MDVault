<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    Check,
    Clock,
    Lock,
    Shield,
    ShieldAlert,
    ShieldCheck,
} from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
        return 'Never (Stay unlocked)';
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

        <!-- Section Header -->
        <div class="space-y-1">
            <h2 class="text-xl font-semibold tracking-tight">Security</h2>
            <p class="text-sm text-muted-foreground">
                Configure auto-lock intervals and workstation lock integration
                for encrypted vaults.
            </p>
        </div>

        <form class="space-y-6" @submit.prevent="save">
            <!-- Inactivity Auto-Lock Card -->
            <Card class="border-border/60 shadow-xs">
                <CardHeader class="pb-4">
                    <div class="flex items-center gap-2.5">
                        <div
                            class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary"
                        >
                            <Clock class="h-4 w-4" />
                        </div>
                        <div>
                            <CardTitle class="text-base font-medium"
                                >Inactivity Auto-Lock</CardTitle
                            >
                            <CardDescription>
                                Automatically lock encrypted vaults when MDVault
                                is left idle.
                            </CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid max-w-sm gap-2">
                        <Label
                            for="auto_lock_minutes"
                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Inactivity Timeout
                        </Label>
                        <Select v-model="form.auto_lock_minutes">
                            <SelectTrigger
                                id="auto_lock_minutes"
                                class="w-full bg-background"
                            >
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
                    </div>

                    <!-- Security Details Banner -->
                    <div
                        class="space-y-2 rounded-xl border border-border/50 bg-muted/20 p-4 text-xs text-muted-foreground"
                    >
                        <div
                            class="flex items-center gap-2 font-medium text-foreground"
                        >
                            <ShieldCheck class="h-4 w-4 text-emerald-500" />
                            <span>Vault Security Guarantees</span>
                        </div>
                        <p class="leading-relaxed">
                            Unlocked vaults purge encryption keys and lock
                            themselves when you haven't interacted with MDVault
                            for the selected duration. Closing the tab,
                            reloading the window, or quitting MDVault always
                            locks all vaults immediately, regardless of this
                            timer.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <!-- Device Screen Lock Card -->
            <Card class="border-border/60 shadow-xs">
                <CardHeader class="pb-3">
                    <div class="flex items-center gap-2.5">
                        <div
                            class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary"
                        >
                            <Lock class="h-4 w-4" />
                        </div>
                        <div>
                            <CardTitle class="text-base font-medium"
                                >Workstation Protection</CardTitle
                            >
                            <CardDescription>
                                Respond to operating system screen lock events.
                            </CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div
                        class="flex items-start gap-3.5 rounded-lg border border-border/50 bg-muted/20 p-4 transition-colors hover:bg-muted/30"
                    >
                        <Checkbox
                            id="lock_on_screen_lock"
                            class="mt-0.5"
                            :model-value="form.lock_on_screen_lock"
                            @update:model-value="
                                (val) =>
                                    (form.lock_on_screen_lock = val === true)
                            "
                        />
                        <div class="space-y-1">
                            <Label
                                for="lock_on_screen_lock"
                                class="cursor-pointer text-sm leading-none font-medium text-foreground"
                            >
                                Lock vaults when the computer's screen locks
                            </Label>
                            <p
                                class="text-xs leading-relaxed text-muted-foreground"
                            >
                                Whenever you lock your operating system (e.g.
                                Win+L or sleep), MDVault locks all open vaults
                                immediately so no notes stay in plain view.
                            </p>
                            <InputError
                                :message="form.errors.lock_on_screen_lock"
                            />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Submit Action Bar -->
            <div
                class="flex items-center justify-between border-t border-border/60 pt-4"
            >
                <p class="text-xs text-muted-foreground">
                    Security policies are enforced instantly on all active
                    vaults.
                </p>
                <Button
                    type="submit"
                    :disabled="form.processing"
                    class="min-w-28 gap-1.5"
                >
                    <Check v-if="!form.processing" class="h-4 w-4" />
                    <span>Save Security</span>
                </Button>
            </div>
        </form>
    </div>
</template>
