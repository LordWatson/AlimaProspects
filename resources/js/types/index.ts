// Everything `@/types` has always exported. The API payload types moved to
// `resources/js/shared/types` in Phase 17 so the mobile app can import the same ones; they are
// re-exported here, so `import type { Lead } from '@/types'` still resolves.
export * from '@/shared/types';

// Web-only: the sidebar, Inertia link props, UI primitives, and the admin panel (Phase 19a —
// deliberately not shared with the mobile app, which has no admin surface).
export * from './admin';
export * from './navigation';
export * from './ui';
