export type VaultStatus = 'active' | 'missing';

export type VaultSummary = {
    uuid: string;
    name: string;
    description: string | null;
    path: string;
    relative_path: string | null;
    status: VaultStatus;
    is_current: boolean;
    is_encrypted: boolean;
    is_unlocked: boolean;
};
