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

export type EditorPreferences = {
    font_size: number;
    font_family: EditorFontFamily;
    line_height: number;
    word_wrap: boolean;
    show_line_numbers: boolean;
};

export type GeneralSettings = {
    check_external_changes: boolean;
};
