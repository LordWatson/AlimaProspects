<?php

declare(strict_types=1);

namespace App\Modules\Core\Support\Security;

use App\Modules\Core\Http\Middleware\SecurityHeaders;

/**
 * Builds the Content-Security-Policy header value for a request (Phase 20, ADR 0017).
 *
 * Nonce-based, not hash-based and not `'unsafe-inline'`. `app.blade.php` carries exactly two
 * inline tags — the appearance script that applies dark mode before first paint, and the style
 * that paints the brand background before the stylesheet lands — and both take the nonce Laravel's
 * Vite helper already mints for the asset tags. That is what makes a strict policy affordable
 * here: there is no third-party widget, no tag manager and no inline `onclick` anywhere in the app.
 *
 * Two directives are deliberately looser than the rest, and both are worth stating plainly rather
 * than discovering later:
 *
 * - **`style-src-attr 'unsafe-inline'`.** Reka UI, vue-sonner and the Inertia progress bar all set
 *   `element.style` to position a popover or animate a toast. Blocking inline *style attributes*
 *   would break them, and a style attribute is not a script: what it enables is defacement, not
 *   execution. The `<style>` *element* stays nonce-gated, which is the directive that matters.
 * - **`img-src data: blob:`.** The brand logo is an inline SVG data URI in places, CSV exports are
 *   handed to the browser as blobs, and the QR code on the 2FA enrolment screen is a data URI.
 *   None of those is a script source.
 *
 * `connect-src` is **derived** from the configuration that already names those hosts — the Reverb
 * socket and the Sentry ingest endpoint — rather than listed again here. A second copy of a
 * hostname is a second place to forget when it changes, and the symptom (a realtime progress bar
 * that silently never connects in production) is one nobody sees in review. Both derivations read
 * the value the **browser** uses, which for the socket is the `VITE_REVERB_*` copy and not the
 * server's own publish target; see {@see self::reverbOrigin()} for why those are not the same
 * thing behind a proxy.
 *
 * @see SecurityHeaders for when the header is emitted and when it is not.
 */
final readonly class ContentSecurityPolicy
{
    public function __construct(private string $nonce) {}

    /**
     * The header value.
     */
    public function value(): string
    {
        $parts = [];

        foreach ($this->directives() as $name => $sources) {
            $parts[] = $sources === []
                ? $name
                : $name.' '.implode(' ', $sources);
        }

        return implode('; ', $parts);
    }

    /**
     * @return array<string, list<string>>
     */
    public function directives(): array
    {
        $nonce = "'nonce-{$this->nonce}'";
        $fontHost = $this->fontHost();

        $directives = [
            // Everything the page may load falls back to same-origin unless a directive below says
            // otherwise, so a directive nobody thought of is restrictive rather than open.
            'default-src' => ["'self'"],

            'script-src' => array_values(array_unique([
                "'self'",
                $nonce,
                ...$this->list('security.csp.extra_script_src'),
            ])),

            // The `<style>` element: same-origin files, the nonce'd inline block in app.blade.php,
            // and the Bunny stylesheet the brand fonts come from.
            // No `array_values` here, unlike `font-src` below: the only nullable source is last,
            // so filtering it out cannot leave a gap in the list.
            'style-src' => array_filter([
                "'self'",
                $nonce,
                $fontHost,
            ]),

            // See the class docblock: a style *attribute* is defacement, not execution.
            'style-src-attr' => ["'unsafe-inline'"],

            'img-src' => array_values(array_unique([
                "'self'",
                'data:',
                'blob:',
                ...$this->list('security.csp.extra_img_src'),
            ])),

            'font-src' => array_values(array_filter([
                "'self'",
                $fontHost,
                'data:',
            ])),

            'connect-src' => $this->connectSources(),

            // No frames, no plugins, no `<base>` rewriting, and a form may only post back to us —
            // the four directives that turn a stored-HTML bug from an exfiltration into a nuisance.
            'frame-src' => ["'none'"],
            'frame-ancestors' => ["'none'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],

            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],
        ];

        if (app()->isProduction()) {
            // Production only: locally the app is served over plain HTTP on a `.test` domain, and
            // upgrading those requests points the browser at a port nothing is listening on.
            $directives['upgrade-insecure-requests'] = [];
        }

        $reportUri = $this->string('security.csp.report_uri');

        if ($reportUri !== null) {
            $directives['report-uri'] = [$reportUri];
        }

        return $directives;
    }

    /**
     * `connect-src`: same-origin, the realtime socket, and the Sentry ingest endpoint.
     *
     * Both derived from their own configuration. A `null` host — no Reverb configured, no frontend
     * DSN — contributes nothing rather than a broken source expression.
     *
     * @return list<string>
     */
    public function connectSources(): array
    {
        $sources = ["'self'"];

        foreach ([$this->reverbOrigin(), $this->sentryOrigin()] as $origin) {
            if ($origin !== null) {
                $sources[] = $origin;
            }
        }

        return array_values(array_unique([...$sources, ...$this->list('security.csp.extra_connect_src')]));
    }

    /**
     * `ws://host:port` or `wss://host:port` for the socket the **browser** opens.
     *
     * From `security.csp.reverb`, which reads the `VITE_REVERB_*` values — the ones
     * `resources/js/echo.ts` actually connects to — and falls back to the server-side `REVERB_*`.
     *
     * That distinction is the correction the Phase 20 review produced: this used to read
     * `broadcasting.connections.reverb.options`, which is where *Laravel* publishes events to.
     * Those agree on a single host and diverge behind a proxy, where the app publishes to an
     * internal address and the page connects to a public one — and a policy derived from the wrong
     * one blocks the socket with nothing in the logs to say so.
     */
    private function reverbOrigin(): ?string
    {
        $host = $this->string('security.csp.reverb.host');

        if ($host === null) {
            return null;
        }

        $scheme = $this->string('security.csp.reverb.scheme') === 'http' ? 'ws' : 'wss';
        $port = $this->string('security.csp.reverb.port');

        return $port === null
            ? $scheme.'://'.$host
            : $scheme.'://'.$host.':'.$port;
    }

    /**
     * The origin the browser SDK posts envelopes to, read out of the frontend DSN.
     *
     * A DSN is `https://<public-key>@<host>/<project-id>`, so the origin is the scheme and host of
     * that URL — never the whole DSN, which carries the key and has no business in a header.
     */
    private function sentryOrigin(): ?string
    {
        $dsn = $this->string('observability.sentry.frontend.dsn');

        if ($dsn === null) {
            return null;
        }

        $parts = parse_url($dsn);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        return isset($parts['port']) ? $origin.':'.$parts['port'] : $origin;
    }

    private function fontHost(): ?string
    {
        return $this->string('security.csp.font_host');
    }

    /**
     * A comma-separated config value as a list, with blanks dropped.
     *
     * @return list<string>
     */
    private function list(string $key): array
    {
        $value = $this->string($key);

        if ($value === null) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== '',
        ));
    }

    private function string(string $key): ?string
    {
        /** @var mixed $value */
        $value = config($key);

        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
