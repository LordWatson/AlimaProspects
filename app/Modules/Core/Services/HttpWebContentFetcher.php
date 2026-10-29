<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\WebContentFetcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Throwable;

/**
 * Minimal, defensive HTTP fetch of a public web page. The URL is almost always a user-supplied,
 * free-text address (a team's own `website`, a lead's site) fetched server-side from a queue worker
 * and read back into the app, so it is an SSRF surface: the fetcher only allows http(s) on ports
 * 80/443, rejects any host that resolves to a loopback/private/link-local/reserved address,
 * re-validates every redirect, caps how much of the body it reads, and strips scripts/styles/markup
 * to plain text. It never executes page content and swallows all errors to an empty string — a
 * background job should degrade, not crash, when a site is slow, down or disallowed. Config:
 * `config/web-fetch.php`.
 */
final class HttpWebContentFetcher implements WebContentFetcher
{
    public function __construct(private readonly HttpFactory $http) {}

    public function fetch(?string $url): string
    {
        $url = $this->normaliseUrl($url);

        if ($url === null) {
            return '';
        }

        try {
            $this->assertSafeUrl($url);

            $response = $this->http
                ->timeout((int) config('web-fetch.timeout', 10))
                ->connectTimeout((int) config('web-fetch.connect_timeout', 5))
                ->withHeaders(['User-Agent' => (string) config('web-fetch.user_agent', 'AlimaProspectsBot/1.0')])
                ->withOptions([
                    'stream' => true,
                    // Re-validate the target of every redirect so a public site can't bounce to an
                    // internal address; cap the hop count.
                    'allow_redirects' => [
                        'max' => 3,
                        'on_redirect' => function ($request, $response, UriInterface $uri): void {
                            $this->assertSafeUrl((string) $uri);
                        },
                    ],
                ])
                ->get($url);

            if ($response->failed()) {
                return '';
            }

            $contentType = (string) $response->header('Content-Type');

            if ($contentType !== '' && ! Str::contains($contentType, ['html', 'text'], ignoreCase: true)) {
                return '';
            }

            return $this->toText($this->readCapped($response));
        } catch (Throwable $e) {
            Log::channel('ai')->warning('Web content fetch failed.', [
                'url' => $url,
                'exception' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Accept a bare domain or a full URL; reject anything that isn't http(s).
     */
    private function normaliseUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        if (! Str::startsWith($url, ['http://', 'https://'])) {
            $url = 'https://'.$url;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], strict: true)) {
            return null;
        }

        return $url;
    }

    /**
     * Reject non-http(s) schemes, non-standard ports, and hosts that resolve to a non-public address
     * (loopback, private, link-local or reserved) — the core SSRF guard.
     */
    private function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? null;

        if (! in_array($scheme, ['http', 'https'], strict: true) || ! is_string($host) || $host === '') {
            throw new RuntimeException('Disallowed URL scheme or host.');
        }

        // parse_url keeps the brackets on an IPv6 literal host (e.g. "[::1]"); strip them so the
        // address validates and can't slip past the private-range check as an unresolvable "host".
        $host = trim($host, '[]');

        if ($port !== null && ! in_array((int) $port, [80, 443], strict: true)) {
            throw new RuntimeException('Disallowed port.');
        }

        foreach ($this->resolveIps($host) as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new RuntimeException('URL resolves to a non-public address.');
            }
        }
    }

    /**
     * Resolve a host to the IPs it points at. A host given as an IP literal resolves to itself. A
     * host that can't be resolved yields no IPs — treated as safe to attempt (the request then just
     * fails on connect), so we never block a legitimate site on a flaky DNS lookup.
     *
     * @return list<string>
     */
    private function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }

        // IPv6, when present.
        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * True for any address outside the public internet (loopback, private, link-local, reserved).
     */
    private function isBlockedIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    /**
     * Read at most `max_bytes` from the (streamed) response body, so an enormous page can't exhaust
     * the worker's memory.
     */
    private function readCapped(Response $response): string
    {
        $maxBytes = (int) config('web-fetch.max_bytes', 500_000);
        $body = $response->toPsrResponse()->getBody();

        $html = '';
        while (! $body->eof() && strlen($html) < $maxBytes) {
            $chunk = $body->read($maxBytes - strlen($html));
            if ($chunk === '') {
                break;
            }
            $html .= $chunk;
        }

        return $html;
    }

    /**
     * Reduce HTML to a bounded block of readable text. Scripts and styles are dropped wholesale so
     * none of their content reaches a later AI prompt, then tags are stripped and whitespace collapsed.
     */
    private function toText(string $html): string
    {
        // Remove script/style/noscript blocks and their contents before stripping tags.
        $html = (string) preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);

        $maxChars = (int) config('web-fetch.max_chars', 12_000);

        return Str::limit($text, $maxChars, '');
    }
}
