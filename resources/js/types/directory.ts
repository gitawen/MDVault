export type DirectoryBreadcrumb = {
    name: string;
    path: string;
};

export type DirectoryDrive = {
    name: string;
    path: string;
};

export type DirectoryQuickLink = {
    name: string;
    path: string;
    icon: string;
};

export type DirectoryItem = {
    name: string;
    path: string;
};

export type DirectoryBrowseResult = {
    current_path: string;
    parent_path: string | null;
    breadcrumbs: DirectoryBreadcrumb[];
    drives: DirectoryDrive[];
    quick_links: DirectoryQuickLink[];
    directories: DirectoryItem[];
    is_writable: boolean;
};
