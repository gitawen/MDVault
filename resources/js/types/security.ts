export type SecuritySettings = {
    auto_lock_minutes: number;
    lock_on_screen_lock: boolean;
};

export type WorkspaceEncryption = {
    locked: boolean;
    unencrypted_files: string[];
    inconsistent: boolean;
};
