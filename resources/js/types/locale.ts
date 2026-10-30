export type LocaleDir = 'ltr' | 'rtl';

export type LocaleOption = {
    code: string;
    label: string;
    native: string;
    dir: LocaleDir;
};

export type LocaleShared = {
    current: string;
    dir: LocaleDir;
    available: LocaleOption[];
    translations: Record<string, string>;
};
