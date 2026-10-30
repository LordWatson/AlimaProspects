import type { Directive } from 'vue';
import type { Auth } from '@/types/auth';
import type { LocaleShared } from '@/types/locale';
import type { NotificationBell } from '@/types/notifications';
import type { OnboardingShared } from '@/types/onboarding';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            locale: LocaleShared;
            onboarding: OnboardingShared | null;
            /** The bell, shared on every page — it is part of the app shell (Phase 12). */
            notifications: NotificationBell | null;
            sidebarOpen: boolean;
            /**
             * For the handful of places that need a real browser form POST rather than an Inertia
             * visit — a file download, where an XHR response cannot reach the user's disk. Inertia
             * visits carry the token in a header and never read this.
             */
            csrfToken: string | null;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface GlobalDirectives {
        vFocus: Directive<HTMLElement, boolean | undefined>;
    }

    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
