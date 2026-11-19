<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Bot,
    Check,
    Copy,
    FolderLock,
    Info,
    Layers,
    Shield,
    Sparkles,
    Terminal,
    Wrench,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { edit, provision } from '@/routes/settings/mcp';
import type { McpSettingsProps, McpVaultAccessSummary } from '@/types';

const props = defineProps<McpSettingsProps>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'AI & MCP settings',
                href: edit(),
            },
        ],
    },
});

type ClientTab = 'json' | 'codex_toml' | 'cli_command';

const activeTab = ref<ClientTab>('json');
const copied = ref(false);
const provisioning = ref(false);

const tabOptions: Array<{ key: ClientTab; label: string; hint: string }> = [
    {
        key: 'json',
        label: 'Claude / Cursor / Antigravity',
        hint: 'Paste into your client MCP JSON configuration (mcpServers).',
    },
    {
        key: 'codex_toml',
        label: 'Codex CLI (TOML)',
        hint: 'Paste into ~/.codex/config.toml.',
    },
    {
        key: 'cli_command',
        label: 'Hermes / CLI',
        hint: 'Run directly over stdio from a terminal or wrapper.',
    },
];

const activeSnippet = computed(() => {
    if (activeTab.value === 'codex_toml') {
        return props.config.codex_toml;
    }

    if (activeTab.value === 'cli_command') {
        return props.config.cli_command;
    }

    return props.config.json;
});

const activeTabMeta = computed(
    () =>
        tabOptions.find((option) => option.key === activeTab.value) ??
        tabOptions[0],
);

async function copySnippet(): Promise<void> {
    try {
        await navigator.clipboard.writeText(activeSnippet.value);
        copied.value = true;
        toast.success('MCP configuration copied to clipboard.');
        window.setTimeout(() => {
            copied.value = false;
        }, 1800);
    } catch {
        toast.error('Unable to copy to clipboard. Copy the snippet manually.');
    }
}

function provisionWorkspace(): void {
    provisioning.value = true;

    router.post(
        provision.url(),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                provisioning.value = false;
            },
        },
    );
}

function tierLabel(vault: McpVaultAccessSummary): string {
    if (vault.tier === 'ai_workspace') {
        return 'AI Workspace · Full CRUD';
    }

    if (vault.tier === 'personal') {
        return 'Personal · Read & Safe Write';
    }

    return vault.exclusion_reason === 'encrypted'
        ? 'Excluded · Encrypted'
        : 'Excluded · Missing';
}

function tierVariant(
    vault: McpVaultAccessSummary,
): 'default' | 'secondary' | 'outline' | 'destructive' {
    if (vault.tier === 'ai_workspace') {
        return 'default';
    }

    if (vault.tier === 'personal') {
        return 'secondary';
    }

    return vault.exclusion_reason === 'encrypted' ? 'outline' : 'destructive';
}
</script>

<template>
    <div class="space-y-6">
        <Head title="AI & MCP settings" />

        <h1 class="sr-only">AI &amp; MCP settings</h1>

        <!-- Section Header -->
        <div class="space-y-1">
            <h2 class="text-xl font-semibold tracking-tight">AI &amp; MCP</h2>
            <p class="text-sm text-muted-foreground">
                Connect local AI clients to MDVault over stdio as a Knowledge
                Operating System (KOS) server.
            </p>
        </div>

        <!-- AI Workspace & Core Memory Card -->
        <Card class="border-border/60 shadow-xs">
            <CardHeader class="pb-4">
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex items-center gap-2.5">
                        <div
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
                        >
                            <Bot class="h-4 w-4" />
                        </div>
                        <div>
                            <CardTitle class="text-base font-medium">
                                AI Workspace &amp; Core Memory
                            </CardTitle>
                            <CardDescription>
                                Dedicated <code class="font-mono">AI</code>
                                vault and
                                <code class="font-mono">AI/about-me.md</code>
                                session memory.
                            </CardDescription>
                        </div>
                    </div>

                    <Badge
                        :variant="
                            workspace.ai_vault.accessible
                                ? 'default'
                                : 'secondary'
                        "
                        class="w-fit text-xs"
                    >
                        {{
                            workspace.ai_vault.accessible
                                ? 'AI Workspace Active'
                                : workspace.ai_vault.provisioned
                                  ? 'AI Vault Excluded'
                                  : 'Not Provisioned'
                        }}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent class="space-y-4">
                <div
                    class="grid grid-cols-1 gap-3 sm:grid-cols-3"
                >
                    <div
                        class="rounded-lg border border-border/50 bg-muted/20 p-3.5"
                    >
                        <p class="text-xs text-muted-foreground">AI Vault</p>
                        <p class="mt-1 text-sm font-medium text-foreground">
                            {{
                                workspace.ai_vault.provisioned
                                    ? workspace.ai_vault.accessible
                                        ? 'Provisioned'
                                        : 'Unavailable'
                                    : 'Auto-provisions on write'
                            }}
                        </p>
                    </div>

                    <div
                        class="rounded-lg border border-border/50 bg-muted/20 p-3.5"
                    >
                        <p class="text-xs text-muted-foreground">
                            Core Memory (<code>about-me.md</code>)
                        </p>
                        <p class="mt-1 text-sm font-medium text-foreground">
                            {{
                                workspace.about_me.exists
                                    ? `Ready (${workspace.about_me.char_count} chars)`
                                    : 'Not created yet'
                            }}
                        </p>
                    </div>

                    <div
                        class="rounded-lg border border-border/50 bg-muted/20 p-3.5"
                    >
                        <p class="text-xs text-muted-foreground">AI Projects</p>
                        <p class="mt-1 text-sm font-medium text-foreground">
                            {{ workspace.ai_projects.length }}
                            {{
                                workspace.ai_projects.length === 1
                                    ? 'project'
                                    : 'projects'
                            }}
                        </p>
                    </div>
                </div>

                <div
                    class="flex flex-col gap-3 rounded-lg border border-border/50 bg-card p-3.5 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p class="text-xs leading-relaxed text-muted-foreground">
                        {{ workspace.ai_vault.note }}
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        :disabled="
                            provisioning ||
                            (workspace.ai_vault.provisioned &&
                                !workspace.ai_vault.accessible)
                        "
                        class="shrink-0"
                        @click="provisionWorkspace"
                    >
                        <Spinner v-if="provisioning" class="mr-2 h-3.5 w-3.5" />
                        <Sparkles v-else class="mr-2 h-3.5 w-3.5" />
                        {{
                            workspace.ai_vault.provisioned &&
                            workspace.about_me.exists
                                ? 'Verify AI Workspace'
                                : 'Provision AI Workspace'
                        }}
                    </Button>
                </div>
            </CardContent>
        </Card>

        <!-- Client Configuration Snippets Card -->
        <Card class="border-border/60 shadow-xs">
            <CardHeader class="pb-4">
                <div class="flex items-center gap-2.5">
                    <div
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
                    >
                        <Terminal class="h-4 w-4" />
                    </div>
                    <div>
                        <CardTitle class="text-base font-medium">
                            Local Stdio Client Configuration
                        </CardTitle>
                        <CardDescription>
                            Server name:
                            <code class="font-mono">{{
                                config.server_name
                            }}</code>
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-4">
                <!-- Client Format Switcher -->
                <div
                    class="flex flex-wrap gap-1.5 rounded-lg border border-border/60 bg-muted/40 p-1"
                >
                    <button
                        v-for="tab in tabOptions"
                        :key="tab.key"
                        type="button"
                        :class="[
                            'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                            activeTab === tab.key
                                ? 'bg-background text-foreground shadow-2xs'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        @click="activeTab = tab.key"
                    >
                        {{ tab.label }}
                    </button>
                </div>

                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs text-muted-foreground">
                        {{ activeTabMeta.hint }}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="shrink-0"
                        @click="copySnippet"
                    >
                        <Check
                            v-if="copied"
                            class="mr-1.5 h-3.5 w-3.5 text-emerald-500"
                        />
                        <Copy v-else class="mr-1.5 h-3.5 w-3.5" />
                        {{ copied ? 'Copied' : 'Copy' }}
                    </Button>
                </div>

                <pre
                    class="overflow-x-auto rounded-lg border border-border/60 bg-muted/40 p-4 font-mono text-xs leading-relaxed text-foreground"
                ><code>{{ activeSnippet }}</code></pre>

                <div
                    class="flex items-start gap-2.5 rounded-lg border border-border/50 bg-muted/20 p-3 text-xs text-muted-foreground"
                >
                    <Info class="mt-0.5 h-3.5 w-3.5 shrink-0 text-foreground" />
                    <span>{{ config.database_note }}</span>
                </div>
            </CardContent>
        </Card>

        <!-- Vault Permissions Summary Card -->
        <Card class="border-border/60 shadow-xs">
            <CardHeader class="pb-4">
                <div class="flex items-center gap-2.5">
                    <div
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
                    >
                        <Shield class="h-4 w-4" />
                    </div>
                    <div>
                        <CardTitle class="text-base font-medium">
                            Vault Permissions &amp; Exclusions
                        </CardTitle>
                        <CardDescription>
                            Two-tier safety model: full CRUD in
                            <code>AI</code>, read + safe write in personal
                            vaults, and strict exclusion for encrypted vaults.
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <div
                    v-if="workspace.vaults.length === 0"
                    class="rounded-lg border border-dashed border-border/60 p-4 text-center text-xs text-muted-foreground"
                >
                    No vaults registered yet. Provision the AI workspace above
                    or create a vault in the workspace.
                </div>

                <div v-else class="space-y-2.5">
                    <div
                        v-for="vault in workspace.vaults"
                        :key="vault.uuid"
                        class="flex flex-col gap-2 rounded-lg border border-border/50 bg-card p-3.5 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0 space-y-0.5">
                            <div class="flex items-center gap-2">
                                <FolderLock
                                    v-if="vault.is_encrypted"
                                    class="h-3.5 w-3.5 shrink-0 text-muted-foreground"
                                />
                                <span
                                    class="truncate text-sm font-medium text-foreground"
                                >
                                    {{ vault.name }}
                                </span>
                                <span
                                    v-if="vault.note_count !== null"
                                    class="text-xs text-muted-foreground"
                                >
                                    ({{ vault.note_count }}
                                    {{
                                        vault.note_count === 1
                                            ? 'note'
                                            : 'notes'
                                    }})
                                </span>
                            </div>
                            <p
                                v-if="vault.description"
                                class="truncate text-xs text-muted-foreground"
                            >
                                {{ vault.description }}
                            </p>
                        </div>

                        <Badge
                            :variant="tierVariant(vault)"
                            class="w-fit shrink-0 text-xs"
                        >
                            {{ tierLabel(vault) }}
                        </Badge>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Registered KOS Primitives Card -->
        <Card class="border-border/60 shadow-xs">
            <CardHeader class="pb-4">
                <div class="flex items-center gap-2.5">
                    <div
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
                    >
                        <Layers class="h-4 w-4" />
                    </div>
                    <div>
                        <CardTitle class="text-base font-medium">
                            Registered MCP Primitives
                        </CardTitle>
                        <CardDescription>
                            {{ workspace.primitives.tools.length }} tools,
                            {{ workspace.primitives.resources.length }}
                            resources, and
                            {{ workspace.primitives.prompts.length }} prompts
                            exposed by <code>MdVaultServer</code>.
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-4">
                <div class="space-y-2">
                    <div class="flex items-center gap-1.5 text-xs font-medium">
                        <Wrench class="h-3.5 w-3.5 text-muted-foreground" />
                        <span>Tools</span>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <Badge
                            v-for="tool in workspace.primitives.tools"
                            :key="tool"
                            variant="secondary"
                            class="font-mono text-[11px]"
                        >
                            {{ tool }}
                        </Badge>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <p class="text-xs font-medium">Resources</p>
                        <ul class="space-y-1">
                            <li
                                v-for="uri in workspace.primitives.resources"
                                :key="uri"
                                class="truncate font-mono text-xs text-muted-foreground"
                            >
                                {{ uri }}
                            </li>
                        </ul>
                    </div>

                    <div class="space-y-2">
                        <p class="text-xs font-medium">Prompts</p>
                        <ul class="space-y-1">
                            <li
                                v-for="prompt in workspace.primitives.prompts"
                                :key="prompt"
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ prompt }}
                            </li>
                        </ul>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
