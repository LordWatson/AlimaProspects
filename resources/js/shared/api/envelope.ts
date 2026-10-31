// The shape of every `/api/v1` response that isn't a resource: the error envelope, the pagination
// wrapper, and the token scopes (Phase 17).
//
// Mirrors, in order: `App\Modules\Core\Http\Responses\ApiError`,
// `App\Modules\Core\Enums\ApiErrorCode`, Laravel's resource-collection envelope, and
// `App\Modules\Auth\Enums\TokenAbility`. The authoritative, generated contract is
// `docs/api/openapi.json`.

/** The API version this client speaks. Sent as `X-Api-Version` and returned on every response. */
export const API_VERSION = '1';

export const API_VERSION_HEADER = 'X-Api-Version';

/**
 * Error codes the framework raises. A module raises its own in the same field — `code` is a
 * namespaced string like `outreach.suppressed` — so always treat an unknown code as "something
 * else went wrong" rather than assuming this union is exhaustive.
 */
/**
 * Any code the API may send: one of the known ones, or a module's own (`outreach.suppressed`).
 * Written this way so an editor still suggests the known codes while the type stays open — the
 * union is never exhaustive, because a module can add a code without a new API version.
 */
export type ApiErrorCodeLike = ApiErrorCode | (string & {});

export type ApiErrorCode =
    | 'unauthenticated'
    | 'forbidden'
    | 'insufficient_token_ability'
    | 'not_found'
    | 'method_not_allowed'
    | 'conflict'
    | 'payload_too_large'
    | 'validation_failed'
    | 'locked'
    | 'too_many_requests'
    | 'unsupported_api_version'
    | 'server_error'
    | 'service_unavailable';

/**
 * Every failure, in one shape. Branch on `code`; show `message`; read `details` only for the codes
 * documented to carry it.
 */
export type ApiErrorEnvelope = {
    error: {
        /** Stable and safe to branch on. Never branch on `message`. */
        code: ApiErrorCodeLike;
        /** Prose for a person. Translated server-side, and free to be reworded within a version. */
        message: string;
        /**
         * Field errors keyed by input name on `validation_failed`;
         * `{ required_abilities: string[] }` on `insufficient_token_ability`.
         */
        details?: Record<string, unknown>;
    };
};

/** Field errors as `validation_failed` reports them. */
export type ApiValidationErrors = Record<string, string[]>;

/** A single resource: `{ data: … }`. */
export type ApiResource<T> = { data: T };

export type PageLinks = {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
};

export type PageMeta = {
    current_page: number;
    from: number | null;
    last_page: number;
    path: string;
    per_page: number;
    to: number | null;
    total: number;
};

/**
 * A page of a list endpoint. The bounded lists (a member's teams, tokens and devices, a team's
 * pipeline stages and ICPs) return {@link ApiResource} with an array instead — they have no page.
 */
export type ApiPage<T> = {
    data: T[];
    links: PageLinks;
    meta: PageMeta;
};

/** The largest page `/api/v1` will serve, matching `Core\Http\Requests\PaginatedIndexRequest`. */
export const MAX_PER_PAGE = 100;

/**
 * What a personal access token may do. A token issued with `'*'` may do everything; identity and
 * a token's own lifecycle need no scope at all.
 */
export type TokenAbility =
    | '*'
    | 'leads:read'
    | 'leads:write'
    | 'discovery:read'
    | 'discovery:write'
    | 'icp:read'
    | 'icp:write'
    | 'scoring:read'
    | 'scoring:write'
    | 'outreach:read'
    | 'outreach:write'
    | 'signals:read'
    | 'signals:write'
    | 'insights:read'
    | 'insights:write'
    | 'notifications:read'
    | 'notifications:write'
    | 'reports:read'
    | 'team:read'
    | 'team:write';
