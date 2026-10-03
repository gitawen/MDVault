<script setup lang="ts">
import { useForm, useHttp } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

function load(path: string): void {
    state.value = { status: 'loading' };

    for (const key of Object.keys(actions)) {
        delete actions[key];
    }

    useHttp<{ path: string }, BackupInspection>({ path }).post(inspect.url(), {
        onSuccess: (response) => {
            state.value = { status: 'ready', inspection: response };

            for (const vault of response.vaults) {
                actions[vault.uuid] = vault.default_action;
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

const canRestore = computed(() =>
    Object.values(actions).some((action) => action !== 'skip'),
);

const form = useForm<{
    path: string;
    vaults: { uuid: string; action: RestoreAction }[];
}>({
    path: '',
    vaults: [],
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
                    Choose what to do with each vault in this backup.
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
                                    v-for="(problem, index) in state
                                        .inspection.problems"
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

                    <div
                        class="min-h-0 flex-1 space-y-4 overflow-y-auto pr-1"
                    >
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
