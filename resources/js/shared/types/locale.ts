export type LocaleDir = 'ltr' | 'rtl';

/** The UI locales the product supports (`Core\Enums\SupportedLocale`). */
export type SupportedLocale = 'en' | 'ar';

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
