<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy } from '@/routes/settings/backup/database';

const open = ref(false);

const page = usePage();
const vaults = computed(() => page.props.vaults);
const total = computed(() => vaults.value.length);
const missing = computed(
    () => vaults.value.filter((vault) => vault.status === 'missing').length,
);
const activeVaults = computed(() =>
    vaults.value.filter((vault) => vault.status === 'active'),
);

const form = useForm({
    confirmation: '',
});

watch(open, (isOpen) => {
    if (!isOpen) {
        form.reset();
        form.clearErrors();
    }
});

function submit() {
    form.submit(destroy(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button variant="destructive" :disabled="total === 0">
                Reset database&#8230;
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Reset MDVault database?</DialogTitle>
                <DialogDescription>
                    This removes {{ total }} vault(s) ({{ missing }} missing)
                    and their note records from MDVault's list.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-2 text-sm text-muted-foreground">
                <p>
                    Kept: your files and folders on disk, your settings, your
                    backup history.
                </p>
                <p v-if="activeVaults.length > 0">
                    These folders still exist on disk and will stay there:
                    {{ activeVaults.map((vault) => vault.name).join(', ') }}. To
                    bring them back afterwards, use Vaults &rarr; Add existing
                    folder. Restoring them from a backup would create
                    &#8220;(restored)&#8221; copies. Consider &#8220;Back up all
                    vaults&#8221; first.
                </p>
            </div>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="reset-database-confirmation">
                        Type RESET to confirm
                    </Label>
                    <Input
                        id="reset-database-confirmation"
                        v-model="form.confirmation"
                        type="text"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.confirmation" />
                </div>

                <DialogFooter>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="
                            form.confirmation !== 'RESET' || form.processing
                        "
                    >
                        Reset database
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
