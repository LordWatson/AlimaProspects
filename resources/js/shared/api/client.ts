// A small, typed client for `/api/v1` — the one place the mobile app (Phase 18) and any other
// consumer need to get the headers, the version pin and the error envelope right.
//
// Deliberately built on `fetch` and nothing else: it has to run inside Capacitor, in a browser and
// in a test, and a dependency that works in two of the three would be a dependency to remove
// later. It knows four things a bare `fetch` does not: send the bearer token, pin the version, read
// the shared error envelope instead of guessing, and surface a `429` as something a caller can
// wait on.

import {
    API_VERSION,
    API_VERSION_HEADER,
    type ApiErrorCodeLike,
    type ApiErrorEnvelope,
    type ApiValidationErrors,
} from './envelope';

/** Resolves the bearer token at call time, so a client survives a sign-in or a refresh. */
export type TokenResolver = () => string | null | Promise<string | null>;

export type ApiClientOptions = {
    /** Origin the API is served from, e.g. `https://app.alima.example`. No trailing slash needed. */
    baseUrl: string;
    /** The acting token, or null before sign-in. */
    token?: TokenResolver;
    /** `Accept-Language`: 'en' or 'ar' (ADR 0007). */
    locale?: () => string;
    /** Swapped in tests. */
    fetch?: typeof globalThis.fetch;
};

export type ApiQuery = Record<
    string,
    string | number | boolean | null | undefined | Array<string | number>
>;

export type ApiRequest = {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    /** Path below `/api/v1`, e.g. `leads/42/stage`. A leading slash is fine. */
    path: string;
    /** Query parameters. Arrays repeat the key as `grades[]`, which is what Laravel reads. */
    query?: ApiQuery;
    /** JSON body. */
    body?: unknown;
    signal?: AbortSignal;
};

/**
 * A failed request, carrying the envelope rather than a string nobody can branch on.
 *
 * `code` is what to switch on. The convenience flags exist because three of the codes mean "do
 * something different" rather than "show the message": sign in again, ask for a wider scope,
 * back off.
 */
export class ApiError extends Error {
    constructor(
        readonly status: number,
        readonly code: ApiErrorCodeLike,
        message: string,
        readonly details: Record<string, unknown> = {},
        /** Seconds to wait, from `Retry-After` on a `429`. */
        readonly retryAfter: number | null = null,
    ) {
        super(message);
        this.name = 'ApiError';
    }

    get isUnauthenticated(): boolean {
        return this.code === 'unauthenticated';
    }

    /**
     * The token is genuine but not scoped for this endpoint; `requiredAbilities` says what would
     * have been enough. Signing in again will not help — the token has to be reissued.
     */
    get isMissingAbility(): boolean {
        return this.code === 'insufficient_token_ability';
    }

    get isThrottled(): boolean {
        return this.code === 'too_many_requests';
    }

    /** Field errors on a `validation_failed`, keyed by input name; empty for every other code. */
    get validationErrors(): ApiValidationErrors {
        if (this.code !== 'validation_failed') {
            return {};
        }

        return this.details as ApiValidationErrors;
    }

    get requiredAbilities(): string[] {
        const required = this.details.required_abilities;

        return Array.isArray(required) ? (required as string[]) : [];
    }
}

export class ApiClient {
    private readonly fetcher: typeof globalThis.fetch;

    constructor(private readonly options: ApiClientOptions) {
        this.fetcher = options.fetch ?? globalThis.fetch.bind(globalThis);
    }

    get<T>(path: string, query?: ApiQuery, signal?: AbortSignal): Promise<T> {
        return this.send<T>({ method: 'GET', path, query, signal });
    }

    post<T>(path: string, body?: unknown, signal?: AbortSignal): Promise<T> {
        return this.send<T>({ method: 'POST', path, body, signal });
    }

    put<T>(path: string, body?: unknown, signal?: AbortSignal): Promise<T> {
        return this.send<T>({ method: 'PUT', path, body, signal });
    }

    patch<T>(path: string, body?: unknown, signal?: AbortSignal): Promise<T> {
        return this.send<T>({ method: 'PATCH', path, body, signal });
    }

    delete<T>(path: string, signal?: AbortSignal): Promise<T> {
        return this.send<T>({ method: 'DELETE', path, signal });
    }

    /**
     * Returns the parsed body, or throws {@link ApiError}. A `204` resolves to `undefined`, which
     * is what every revoke and delete endpoint answers with.
     */
    async send<T>(request: ApiRequest): Promise<T> {
        const token = await this.options.token?.();

        const headers: Record<string, string> = {
            Accept: 'application/json',
            [API_VERSION_HEADER]: API_VERSION,
        };

        if (token) {
            headers.Authorization = 'Bearer ' + token;
        }

        if (this.options.locale) {
            headers['Accept-Language'] = this.options.locale();
        }

        if (request.body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }

        const response = await this.fetcher(this.url(request), {
            method: request.method ?? 'GET',
            headers,
            body:
                request.body === undefined
                    ? undefined
                    : JSON.stringify(request.body),
            signal: request.signal,
        });

        const payload = await this.parse(response);

        if (!response.ok) {
            throw this.toError(response, payload);
        }

        return payload as T;
    }

    private url(request: ApiRequest): string {
        const base = this.options.baseUrl.replace(/\/+$/, '');
        const path = request.path.replace(/^\/+/, '');
        const url = new URL(base + '/api/v1/' + path);

        for (const [key, value] of Object.entries(request.query ?? {})) {
            if (value === null || value === undefined) {
                continue;
            }

            if (Array.isArray(value)) {
                value.forEach((entry) =>
                    url.searchParams.append(key + '[]', String(entry)),
                );
                continue;
            }

            url.searchParams.set(key, String(value));
        }

        return url.toString();
    }

    private async parse(response: Response): Promise<unknown> {
        const text = await response.text();

        if (text === '') {
            return undefined;
        }

        try {
            return JSON.parse(text) as unknown;
        } catch {
            // A proxy, a maintenance page, or an HTML error page from in front of the app.
            return undefined;
        }
    }

    private toError(response: Response, payload: unknown): ApiError {
        const envelope = payload as Partial<ApiErrorEnvelope> | undefined;
        const error = envelope?.error;
        const retryAfter = Number.parseInt(
            response.headers.get('Retry-After') ?? '',
            10,
        );

        return new ApiError(
            response.status,
            error?.code ?? 'server_error',
            // An error without the envelope did not come from the app — it came from something in
            // front of it. Say so, rather than inventing a message the API never sent.
            error?.message ??
                'The API answered ' +
                    response.status +
                    ' without an error envelope.',
            error?.details ?? {},
            Number.isNaN(retryAfter) ? null : retryAfter,
        );
    }
}
