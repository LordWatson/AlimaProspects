import { describe, expect, it, vi } from 'vitest';
import { ApiClient, ApiError } from '@shared/api';

/*
 * The shared `/api/v1` client (`resources/js/shared/api/client.ts`).
 *
 * Phase 17 wrote it and Phase 18 is the first thing to depend on it, so this is where it gets its
 * tests. They are **contract** tests, not implementation ones: every case here mirrors a sentence
 * in `docs/api/README.md` that the client is the client-side half of. If one of these fails, a
 * screen misreads the API — which is the kind of fault that reaches a handset and nothing else.
 *
 * It is tested through the injected `fetch` rather than against a server, because what is being
 * checked is what the client *sends* and how it reads what comes back.
 */
function respond(
    body: unknown,
    init: { status?: number; headers?: Record<string, string> } = {},
): Response {
    return new Response(body === undefined ? '' : JSON.stringify(body), {
        status: init.status ?? 200,
        headers: { 'Content-Type': 'application/json', ...init.headers },
    });
}

function clientWith(
    fetcher: ReturnType<typeof vi.fn>,
    options: { token?: string | null; locale?: string } = {},
): ApiClient {
    return new ApiClient({
        baseUrl: 'https://alima.test',
        token: () => options.token ?? null,
        locale:
            options.locale === undefined
                ? undefined
                : (): string => options.locale ?? 'en',
        fetch: fetcher as unknown as typeof globalThis.fetch,
    });
}

describe('what it sends', () => {
    it('pins the version on every request (§2)', async () => {
        const fetcher = vi.fn().mockResolvedValue(respond({ data: {} }));

        await clientWith(fetcher).get('user');

        const headers = fetcher.mock.calls[0][1].headers as Record<
            string,
            string
        >;

        expect(headers['X-Api-Version']).toBe('1');
    });

    it('sends the bearer token when there is one, and no header when there is not', async () => {
        const withToken = vi.fn().mockResolvedValue(respond({ data: {} }));
        const without = vi.fn().mockResolvedValue(respond({ data: {} }));

        await clientWith(withToken, { token: 'abc' }).get('user');
        await clientWith(without).get('user');

        expect(
            (withToken.mock.calls[0][1].headers as Record<string, string>)
                .Authorization,
        ).toBe('Bearer abc');
        expect(
            (without.mock.calls[0][1].headers as Record<string, string>)
                .Authorization,
        ).toBeUndefined();
    });

    it('asks for the caller’s language, which is what translates the error prose (§4)', async () => {
        const fetcher = vi.fn().mockResolvedValue(respond({ data: {} }));

        await clientWith(fetcher, { locale: 'ar' }).get('user');

        expect(
            (fetcher.mock.calls[0][1].headers as Record<string, string>)[
                'Accept-Language'
            ],
        ).toBe('ar');
    });

    it('repeats an array parameter as `key[]`, which is what Laravel reads', async () => {
        const fetcher = vi.fn().mockResolvedValue(respond({ data: [] }));

        await clientWith(fetcher).get('leads', {
            grades: ['A', 'B'],
            per_page: 20,
        });

        const url = String(fetcher.mock.calls[0][0]);

        expect(url).toContain('grades%5B%5D=A');
        expect(url).toContain('grades%5B%5D=B');
        expect(url).toContain('per_page=20');
    });

    it('drops a null or undefined parameter rather than sending the word "null"', async () => {
        const fetcher = vi.fn().mockResolvedValue(respond({ data: [] }));

        await clientWith(fetcher).get('leads', {
            search: null,
            country: undefined,
        });

        expect(String(fetcher.mock.calls[0][0])).not.toContain('search');
    });

    it('builds one path however the base and the path are slashed', async () => {
        const fetcher = vi.fn().mockResolvedValue(respond({ data: {} }));

        const client = new ApiClient({
            baseUrl: 'https://alima.test/',
            fetch: fetcher as unknown as typeof globalThis.fetch,
        });

        await client.get('/leads/42');

        expect(String(fetcher.mock.calls[0][0])).toBe(
            'https://alima.test/api/v1/leads/42',
        );
    });
});

describe('what it reads back', () => {
    it('resolves a 204 to undefined — which is every revoke and delete (§7)', async () => {
        // `null` rather than `''`: a 204 carries no body, and the Response constructor refuses
        // an empty string for one.
        const fetcher = vi
            .fn()
            .mockResolvedValue(new Response(null, { status: 204 }));

        await expect(
            clientWith(fetcher).delete('auth/token'),
        ).resolves.toBeUndefined();
    });

    it('throws the envelope’s code, not its prose (§4)', async () => {
        const fetcher = vi.fn().mockResolvedValue(
            respond(
                {
                    error: {
                        code: 'insufficient_token_ability',
                        message: 'This token is not allowed to do that.',
                        details: { required_abilities: ['leads:write'] },
                    },
                },
                { status: 403 },
            ),
        );

        const error = await clientWith(fetcher)
            .post('leads/1/notes', { body: 'no' })
            .catch((thrown: unknown) => thrown);

        expect(error).toBeInstanceOf(ApiError);
        expect((error as ApiError).code).toBe('insufficient_token_ability');
        // The distinction the app acts on: this is not "sign in again", it is "reissue the token".
        expect((error as ApiError).isMissingAbility).toBe(true);
        expect((error as ApiError).isUnauthenticated).toBe(false);
        expect((error as ApiError).requiredAbilities).toEqual(['leads:write']);
    });

    it('surfaces a 429 as something a caller can wait on (§6)', async () => {
        const fetcher = vi.fn().mockResolvedValue(
            respond(
                {
                    error: {
                        code: 'too_many_requests',
                        message: 'Slow down.',
                    },
                },
                { status: 429, 'Retry-After': '30' } as never,
            ),
        );

        const error = (await clientWith(fetcher)
            .get('leads')
            .catch((thrown: unknown) => thrown)) as ApiError;

        expect(error.isThrottled).toBe(true);
    });

    it('gives field errors only for a validation failure', async () => {
        const fetcher = vi.fn().mockResolvedValue(
            respond(
                {
                    error: {
                        code: 'validation_failed',
                        message: 'Some of those details need another look.',
                        details: { company_name: ['Required.'] },
                    },
                },
                { status: 422 },
            ),
        );

        const error = (await clientWith(fetcher)
            .post('leads', {})
            .catch((thrown: unknown) => thrown)) as ApiError;

        expect(error.validationErrors).toEqual({ company_name: ['Required.'] });
    });

    it('says so when an error arrived without the envelope', async () => {
        // A maintenance page or a proxy in front of the app. Inventing a message the API never
        // sent would send someone looking for a bug in Alima.
        const fetcher = vi.fn().mockResolvedValue(
            new Response('<html>502</html>', {
                status: 502,
                headers: { 'Content-Type': 'text/html' },
            }),
        );

        const error = (await clientWith(fetcher)
            .get('leads')
            .catch((thrown: unknown) => thrown)) as ApiError;

        expect(error.code).toBe('server_error');
        expect(error.message).toContain('502');
    });

    it('resolves the token at call time, so a sign-in takes effect on the next request', async () => {
        // A fresh Response per call: a body can only be read once, so one mocked value would
        // fail on the second request for a reason that has nothing to do with the token.
        const fetcher = vi.fn().mockImplementation(() => respond({ data: {} }));
        let token: string | null = null;

        const client = new ApiClient({
            baseUrl: 'https://alima.test',
            token: () => token,
            fetch: fetcher as unknown as typeof globalThis.fetch,
        });

        await client.get('user');
        token = 'fresh';
        await client.get('user');

        expect(
            (fetcher.mock.calls[1][1].headers as Record<string, string>)
                .Authorization,
        ).toBe('Bearer fresh');
    });
});
