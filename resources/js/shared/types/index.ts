// The API payload types the web app and the mobile app (Phase 18) both read.
//
// These mirror the `/api/v1` resources — the Inertia screens are handed the same shapes by the
// same Resource classes, which is why one set of types serves both and why neither app has its
// own. They live under `resources/js/shared/` so the Capacitor + Ionic Vue app can import them
// through a path alias rather than copying them (Phase 17).
//
// The machine-readable contract is `docs/api/openapi.json`, generated from the routes and
// Resources themselves by `composer api:docs`. These types are the hand-written, friendlier
// mirror of it: they carry what the specification cannot express — which `label` fields are
// English **translation keys** rather than finished strings (ADR 0007), and which unions are
// discriminated. When the two disagree, the specification is right and these are stale.
//
// Web-only types (navigation, UI primitives, Inertia's shared props) stay in `@/types`, which
// re-exports everything here so existing imports keep working.

export * from './ai-insights';
export * from './auth';
export * from './billing';
export * from './business-intel';
export * from './compliance';
export * from './enrichment';
export * from './icp';
export * from './lead-sourcing';
export * from './leads-workspace';
export * from './locale';
export * from './notifications';
export * from './onboarding';
export * from './outreach';
export * from './reporting';
export * from './scoring';
export * from './signals';
