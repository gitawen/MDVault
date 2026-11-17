<script setup lang="ts">
import { useForm, useHttp } from '@inertiajs/vue3';
import { AlertTriangle, Lock, SlidersHorizontal } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatBytes } from '@/lib/formatBytes';
import {
    inspect,
    store as restoreStore,
} from '@/routes/settings/backup/restore';
import type { BackupInspection, RestoreAction } from '@/types';

const props = defineProps<{
    path: string | null;
}>();

const open = defineModel<boolean>('open', { default: false });

type LoadState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'error'; message: string }
    | { status: 'ready'; inspection: BackupInspection };

const state = ref<LoadState>({ status: 'idle' });
const actions = reactive<Record<string, RestoreAction>>({});
const selectedGroups = reactive<Record<string, boolean>>({});

function load(path: string): void {
    state.value = { status: 'loading' };

    for (const key of Object.keys(actions)) {
        delete actions[key];
    }

    for (const key of Object.keys(selectedGroups)) {
        delete selectedGroups[key];
    }

    useHttp<{ path: string }, BackupInspection>({ path }).post(inspect.url(), {
        onSuccess: (response) => {
            state.value = { status: 'ready', inspection: response };

            for (const vault of response.vaults) {
                actions[vault.uuid] = vault.default_action;
            }

            const settings = response.backup?.settings;

            if (settings) {
                for (const group of settings.groups) {
                    selectedGroups[group.group] = settings.default_restore;
                }
            }
        },
        onError: (errors) => {
            const message =
                (errors as Record<string, string | undefined>).path ??
                "This backup can't be checked.";
            state.value = { status: 'error', message };
        },
    });
}

watch(
    [open, () => props.path],
    ([isOpen, path]) => {
        if (isOpen && path) {
            load(path);
        }
    },
    { immediate: true },
);

const selectedSettingGroups = computed(() =>
    Object.entries(selectedGroups)
        .filter(([, checked]) => checked)
        .map(([group]) => group),
);

const hasSelectedSettingsConflict = computed(() => {
    if (state.value.status !== 'ready') {
        return false;
    }

    const groups = state.value.inspection.backup?.settings?.groups ?? [];

    return groups.some(
        (group) => selectedGroups[group.group] && group.differing > 0,
    );
});

const canRestore = computed(
    () =>
        Object.values(actions).some((action) => action !== 'skip') ||
        selectedSettingGroups.value.length > 0,
);

const form = useForm<{
    path: string;
    vaults: { uuid: string; action: RestoreAction }[];
    setting_groups: string[];
}>({
    path: '',
    vaults: [],
    setting_groups: [],
});

function submit(): void {
    if (state.value.status !== 'ready' || !props.path) {
        return;
    }

    const inspection = state.value.inspection;

    form.path = props.path;
    form.vaults = inspection.vaults.map((vault) => ({
        uuid: vault.uuid,
        action: actions[vault.uuid] ?? 'skip',
    }));
    form.setting_groups = selectedSettingGroups.value;

    form.post(restoreStore.url(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}

function close(): void {
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex max-h-[85vh] max-w-xl flex-col overflow-hidden"
        >
            <DialogHeader class="shrink-0">
                <DialogTitle>Restore from backup</DialogTitle>
                <DialogDescription>
                    Choose what to do with each vault and any included settings
                    in this backup.
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="state.status === 'loading'"
                class="shrink-0 text-sm text-muted-foreground"
            >
                Checking the backup&#8230; This can take a while for large
                backups.
            </p>

            <Alert
                v-else-if="state.status === 'error'"
                variant="destructive"
                class="shrink-0"
            >
                <AlertDescription>{{ state.message }}</AlertDescription>
            </Alert>

            <template v-else-if="state.status === 'ready'">
                <div
                    v-if="!state.inspection.valid"
                    class="min-h-0 flex-1 overflow-y-auto"
                >
                    <Alert variant="destructive">
                        <AlertTitle
                            >This backup can&#8217;t be restored.</AlertTitle
                        >
                        <AlertDescription>
                            <ul class="list-disc pl-4">
                                <li
                                    v-for="(problem, index) in state.inspection
                                        .problems"
                                    :key="index"
                                >
                                    {{ problem }}
                                </li>
                            </ul>
                        </AlertDescription>
                    </Alert>
                </div>

                <template v-else>
                    <div
                        class="shrink-0 space-y-1 text-sm text-muted-foreground"
                    >
                        <p>
                            {{
                                new Date(
                                    state.inspection.backup!.created_at,
                                ).toLocaleString()
                            }}
                            &middot; MDVault
                            {{ state.inspection.backup!.app_version }}
                        </p>
                        <p>
                            {{ state.inspection.backup!.vault_count }}
                            vault(s),
                            {{ state.inspection.backup!.note_count }}
                            note(s),
                            {{
                                formatBytes(
                                    state.inspection.backup!.total_bytes,
                                )
                            }}
                        </p>
                    </div>

                    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto pr-1">
                        <div
                            v-for="vault in state.inspection.vaults"
                            :key="vault.uuid"
                            class="space-y-2 rounded-md border p-3"
                        >
                            <div>
                                <p class="flex items-center gap-2 font-medium">
                                    {{ vault.name }}
                                    <Badge
                                        v-if="vault.is_encrypted"
                                        variant="outline"
                                        class="gap-1"
                                    >
                                        <Lock class="size-3.5" />
                                        Encrypted
                                    </Badge>
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ vault.note_count }} note(s),
                                    {{ formatBytes(vault.total_bytes) }}
                                </p>
                                <p
                                    v-if="vault.is_encrypted"
                                    class="text-xs text-muted-foreground"
                                >
                                    You'll need its password. It is restored
                                    locked, and opens with the password it had
                                    when the backup was made.
                                </p>
                            </div>

                            <div class="grid gap-2">
                                <Label :for="`restore-action-${vault.uuid}`"
                                    >Action</Label
                                >
                                <Select v-model="actions[vault.uuid]">
                                    <SelectTrigger
                                        :id="`restore-action-${vault.uuid}`"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <template v-if="vault.state === 'new'">
                                            <SelectItem value="restore"
                                                >Restore</SelectItem
                                            >
                                            <SelectItem value="skip"
                                                >Skip</SelectItem
                                            >
                                        </template>
                                        <template v-else>
                                            <SelectItem value="skip"
                                                >Skip</SelectItem
                                            >
                                            <SelectItem value="copy"
                                                >Restore as a copy</SelectItem
                                            >
                                        </template>
                                    </SelectContent>
                                </Select>
                                <p class="text-xs text-muted-foreground">
                                    <template v-if="vault.state === 'new'">
                                        Will be restored as &#8220;{{
                                            vault.restore_name ?? vault.name
                                        }}&#8221;.
                                    </template>
                                    <template v-else>
                                        Already in MDVault. A copy would be
                                        named &#8220;{{
                                            vault.copy_name
                                        }}&#8221; and get new identities.
                                    </template>
                                </p>
                            </div>
                        </div>

                        <div
                            v-if="state.inspection.backup?.settings"
                            class="space-y-3 rounded-md border p-3"
                        >
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <div class="flex items-center gap-2">
                                    <SlidersHorizontal
                                        class="size-4 text-muted-foreground"
                                    />
                                    <p class="text-sm font-medium">
                                        Application Settings
                                    </p>
                                </div>
                                <span class="text-xs text-muted-foreground">
                                    {{
                                        state.inspection.backup.settings
                                            .total_count
                                    }}
                                    setting(s)
                                </span>
                            </div>

                            <div class="space-y-2">
                                <div
                                    v-for="group in state.inspection.backup
                                        .settings.groups"
                                    :key="group.group"
                                    class="flex items-center justify-between gap-3 rounded-md border border-border/50 bg-muted/20 px-3 py-2"
                                >
                                    <div class="flex items-center gap-2.5">
                                        <Checkbox
                                            :id="`restore-setting-group-${group.group}`"
                                            :model-value="
                                                selectedGroups[group.group] ===
                                                true
                                            "
                                            @update:model-value="
                                                (val) =>
                                                    (selectedGroups[
                                                        group.group
                                                    ] = val === true)
                                            "
                                        />
                                        <Label
                                            :for="`restore-setting-group-${group.group}`"
                                            class="cursor-pointer text-sm font-medium"
                                        >
                                            {{ group.label }}
                                        </Label>
                                        <span
                                            class="text-xs text-muted-foreground"
                                        >
                                            ({{ group.count }})
                                        </span>
                                    </div>
                                    <Badge
                                        v-if="group.differing > 0"
                                        variant="outline"
                                        class="border-amber-500/40 bg-amber-500/10 text-xs text-amber-700 dark:text-amber-400"
                                    >
                                        {{ group.differing }} differ
                                    </Badge>
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                    >
                                        Matches current
                                    </span>
                                </div>
                            </div>

                            <div
                                v-if="hasSelectedSettingsConflict"
                                class="flex items-start gap-2 rounded-md border border-amber-500/20 bg-amber-500/10 p-2.5 text-xs text-amber-700 dark:text-amber-400"
                            >
                                <AlertTriangle
                                    class="mt-0.5 size-3.5 shrink-0"
                                />
                                <span>
                                    Checked groups with differences will
                                    overwrite your current preferences for those
                                    groups.
                                </span>
                            </div>

                            <p class="text-xs text-muted-foreground">
                                Storage paths and backup history are never
                                overwritten.
                            </p>
                        </div>

                        <p
                            v-else
                            class="rounded-md border border-dashed p-3 text-xs text-muted-foreground"
                        >
                            This older backup format does not include
                            application settings.
                        </p>
                    </div>

                    <div class="shrink-0 space-y-2">
                        <p
                            v-if="form.processing"
                            class="text-sm text-muted-foreground"
                        >
                            Restoring&#8230; don&#8217;t close MDVault.
                        </p>

                        <InputError :message="form.errors.path" />
                        <InputError :message="form.errors.vaults" />
                        <InputError :message="form.errors.setting_groups" />
                    </div>
                </template>
            </template>

            <DialogFooter class="shrink-0">
                <Button type="button" variant="outline" @click="close">
                    Cancel
                </Button>
                <Button
                    v-if="state.status === 'ready' && state.inspection.valid"
                    type="button"
                    :disabled="!canRestore || form.processing"
                    @click="submit"
                >
                    Restore
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
