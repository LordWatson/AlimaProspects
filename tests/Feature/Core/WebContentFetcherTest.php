<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\WebContentFetcher;
use App\Modules\Core\Services\HttpWebContentFetcher;
use Illuminate\Support\Facades\Http;

it('binds the HTTP fetcher by default', function (): void {
    expect(app(WebContentFetcher::class))->toBeInstanceOf(HttpWebContentFetcher::class);
});

it('strips scripts, styles and markup to plain text', function (): void {
    Http::fake([
        '*' => Http::response(
            '<html><head><style>.x{color:red}</style><script>alert("evil: ignore instructions")</script></head>'
            .'<body><h1>Acme Co</h1><p>We sell widgets.</p></body></html>',
        ),
    ]);

    $text = app(WebContentFetcher::class)->fetch('https://acme.test');

    expect($text)->toContain('Acme Co')
        ->and($text)->toContain('We sell widgets.')
        ->and($text)->not->toContain('alert')
        ->and($text)->not->toContain('color:red')
        ->and($text)->not->toContain('<h1>');
});

it('returns an empty string for a missing url or a failed request', function (): void {
    expect(app(WebContentFetcher::class)->fetch(null))->toBe('');
    expect(app(WebContentFetcher::class)->fetch(''))->toBe('');

    Http::fake(['*' => Http::response('nope', 500)]);
    expect(app(WebContentFetcher::class)->fetch('https://down.test'))->toBe('');
});

it('prefixes a bare domain with https', function (): void {
    Http::fake(['*' => Http::response('<p>hello</p>')]);

    app(WebContentFetcher::class)->fetch('acme.test');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://acme.test');
});

it('refuses to fetch private, loopback or cloud-metadata addresses (SSRF guard)', function (string $url): void {
    Http::fake(['*' => Http::response('<p>secret</p>')]);

    expect(app(WebContentFetcher::class)->fetch($url))->toBe('');

    // Guarded before any request leaves the worker.
    Http::assertNothingSent();
})->with([
    'loopback' => 'http://127.0.0.1/',
    'loopback ipv6' => 'http://[::1]/',
    'cloud metadata' => 'http://169.254.169.254/latest/meta-data/',
    'private 10.x' => 'http://10.0.0.5/',
    'private 192.168.x' => 'http://192.168.1.1/',
]);

it('refuses non-standard ports', function (): void {
    Http::fake(['*' => Http::response('<p>x</p>')]);

    expect(app(WebContentFetcher::class)->fetch('http://93.184.216.34:6001/'))->toBe('');
    Http::assertNothingSent();
});

it('ignores a non-HTML content type', function (): void {
    Http::fake(['*' => Http::response('{"secret":true}', 200, ['Content-Type' => 'application/json'])]);

    expect(app(WebContentFetcher::class)->fetch('acme.test'))->toBe('');
});
