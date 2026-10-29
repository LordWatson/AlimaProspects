<?php

declare(strict_types=1);

use App\Modules\Signals\Services\SignalItemNormaliser;

beforeEach(function (): void {
    $this->normaliser = new SignalItemNormaliser;
});

it('lower-cases scheme and host, drops www and a trailing slash', function (): void {
    expect($this->normaliser->canonicalUrl('HTTPS://WWW.GulfBusiness.com/Acme-Series-A/'))
        ->toBe('https://gulfbusiness.com/Acme-Series-A');
});

it('strips utm_* parameters and the fragment but keeps real query params', function (): void {
    expect($this->normaliser->canonicalUrl('https://news.ae/story?utm_source=twitter&id=7&utm_medium=social#top'))
        ->toBe('https://news.ae/story?id=7');
});

it('sorts the remaining query params so order never changes the hash', function (): void {
    $a = $this->normaliser->urlHash('https://news.ae/story?b=2&a=1');
    $b = $this->normaliser->urlHash('https://news.ae/story?a=1&b=2');

    expect($a)->toBe($b);
});

it('dedupes the same article carrying tracking parameters', function (): void {
    $clean = $this->normaliser->urlHash('https://gulfbusiness.com/acme-series-a');
    $tracked = $this->normaliser->urlHash('https://www.gulfbusiness.com/acme-series-a/?utm_campaign=newsletter#share');

    expect($tracked)->toBe($clean)
        ->and($clean)->toHaveLength(40);
});

it('rejects non-http(s) and unparseable URLs', function (string $url): void {
    expect($this->normaliser->canonicalUrl($url))->toBeNull()
        ->and($this->normaliser->urlHash($url))->toBeNull();
})->with([
    'empty' => '',
    'javascript' => 'javascript:alert(1)',
    'ftp' => 'ftp://example.com/file',
    'relative' => '/just/a/path',
    'scheme only' => 'https://',
]);
