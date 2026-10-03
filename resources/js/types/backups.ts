export type RestoreAction = 'restore' | 'copy' | 'skip';

export type BackupRecord = {
    uuid: string;
    scope: 'all' | 'vault';
    filename: string;
    path: string;
    file_size: number;
    vault_count: number;
    note_count: number;
    file_count: number;
    vaults: string[];
    created_at: string;
    exists: boolean;
};

export type BackupInspectionVault = {
    uuid: string;
    name: string;
    description: string | null;
    is_encrypted: boolean;
    note_count: number;
    file_count: number;
    total_bytes: number;
    state: 'new' | 'exists';
    restore_name: string | null;
    copy_name: string | null;
    default_action: RestoreAction;
};

export type BackupInspection = {
    valid: boolean;
    problems: string[];
    backup: {
        created_at: string;
        app_version: string;
        format_version: number;
        scope: 'all' | 'vault';
        vault_count: number;
        note_count: number;
        file_count: number;
        total_bytes: number;
        archive_size: number;
    } | null;
    vaults: BackupInspectionVault[];
};
