<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AddExistingVaultDialog from '@/components/vaults/AddExistingVaultDialog.vue';
import CreateVaultDialog from '@/components/vaults/CreateVaultDialog.vue';
import RemoveVaultDialog from '@/components/vaults/RemoveVaultDialog.vue';
import RenameVaultDialog from '@/components/vaults/RenameVaultDialog.vue';
import VaultStatusBadge from '@/components/vaults/VaultStatusBadge.vue';
import { index, open } from '@/routes/vaults';

defineProps<{
    storageRoot: string;
    canBrowse: boolean;
    canTrash: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Vaults', href: index() }],
    },
});

const page = usePage();
const vaults = computed(() => page.props.vaults);

function openVault(uuid: string) {
    router.post(open.url(uuid));
}
</script>

<template>
    <div class="flex flex-col gap-6 p-4">
        <Head title="Vaults" />

        <Heading
            title="Vaults"
            description="Folders of Markdown notes managed by MDVault."
        />

        <div class="flex flex-wrap items-center gap-2">
            <CreateVaultDialog :storage-root="storageRoot" />
            <AddExistingVaultDialog :can-browse="canBrowse" />
        </div>

        <div v-if="vaults.length === 0" class="text-sm text-muted-foreground">
            No vaults yet. Create one to get started.
        </div>

        <div v-else class="grid gap-4">
            <Card v-for="vault in vaults" :key="vault.uuid">
                <CardHeader>
                    <div class="flex flex-wrap items-center gap-2">
                        <CardTitle>{{ vault.name }}</CardTitle>
                        <Badge v-if="vault.is_current" variant="secondary">
                            Current
                        </Badge>
                        <VaultStatusBadge :status="vault.status" />
                    </div>
                </CardHeader>
                <CardContent class="space-y-3">
                    <p
                        v-if="vault.description"
                        class="text-sm text-muted-foreground"
                    >
                        {{ vault.description }}
                    </p>
                    <p
                        class="font-mono text-xs break-all text-muted-foreground"
                    >
                        {{ vault.path }}
                    </p>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            size="sm"
                            :disabled="vault.status !== 'active'"
                            @click="openVault(vault.uuid)"
                        >
                            Open
                        </Button>
                        <RenameVaultDialog :vault="vault" />
                        <RemoveVaultDialog
                            :vault="vault"
                            :can-trash="canTrash"
                        />
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
