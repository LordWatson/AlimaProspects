import type { Directive } from 'vue';
import type { Auth } from '@/shared/types/auth';
import type {
    AnnouncementBannerData,
    AppCapability,
    ImpersonationBannerData,
    ProvisionPreview,
} from '@/types/admin';
import type { LocaleShared } from '@/shared/types/locale';
import type { NotificationBell } from '@/shared/types/notifications';
import type { OnboardingShared } from '@/shared/types/onboarding';

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
            /**
             * What the active team's plan includes (Phase 19b). A **map**, not a list: an absent key
             * reads as available, so a page that did not send the prop cannot silently hide half
             * the nav.
             */
            capabilities: Partial<Record<AppCapability, boolean>>;
            /** Live platform announcements for this reader (Phase 19b). */
            announcements: AnnouncementBannerData[];
            /**
             * The provisioning dry run's answer, flashed for one request (Phase 19b). Absent on
             * every other page, which is why it is optional.
             */
            provision_preview?: ProvisionPreview | null;
            csrfToken: string | null;
            /**
             * Present only while an operator is impersonating a customer (Phase 19a). Shared on
             * every web request by `ShareImpersonation`, so the banner cannot be missing from a
             * tenant screen somebody forgot to add it to.
             */
            impersonation?: ImpersonationBannerData | null;
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
