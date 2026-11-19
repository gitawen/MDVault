export type StorageSettings = {
    root_path: string;
    default_path: string;
    is_default: boolean;
    exists: boolean;
    writable: boolean;
    location: string;
    folder_name: string;
};

export type EditorFontFamily = 'sans' | 'serif' | 'mono';

export type EditorDefaultView = 'code' | 'split' | 'preview';

export type EditorPreferences = {
    font_size: number;
    font_family: EditorFontFamily;
    line_height: number;
    word_wrap: boolean;
    show_line_numbers: boolean;
    indent_size: number;
    default_view: EditorDefaultView;
    new_note_template_enabled: boolean;
    new_note_template: string;
};

export type GeneralSettings = {
    check_external_changes: boolean;
};

export type McpClientSnippet = {
    server_name: string;
    command: string;
    args: string[];
    env: Record<string, string>;
    database_path: string;
    uses_custom_database: boolean;
    database_note: string;
    json: string;
    codex_toml: string;
    cli_command: string;
};

export type McpVaultAccessSummary = {
    uuid: string;
    name: string;
    description: string | null;
    status: string;
    is_current: boolean;
    is_encrypted: boolean;
    accessible: boolean;
    tier: 'ai_workspace' | 'personal' | 'excluded';
    permission: 'full_crud' | 'read_safe_write' | 'none';
    note_count: number | null;
    exclusion_reason: string | null;
};

export type McpSettingsProps = {
    config: McpClientSnippet;
    workspace: {
        ai_vault: {
            name: string;
            provisioned: boolean;
            accessible: boolean;
            vault_uuid: string | null;
            note: string;
        };
        about_me: {
            exists: boolean;
            relative_path: string;
            char_count: number;
        };
        ai_projects: string[];
        vaults: McpVaultAccessSummary[];
        primitives: {
            tools: string[];
            resources: string[];
            prompts: string[];
        };
    };
};
