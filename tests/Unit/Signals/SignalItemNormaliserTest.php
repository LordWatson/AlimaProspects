<?php

declare(strict_types=1);

use App\Modules\Signals\Services\SignalItemNormaliser;
use Tests\TestCase;

/*
 * Boots the application, which {@see SignalItemNormaliser} needs since S11: the redirector list is
 * configuration (`signals.redirect_hosts`), not a constant, so the next aggregator that wraps its
 * links is a line in a file rather than a release. That makes this a unit test of a class that
 * reads config rather than a pure one, which is the honest trade for not holding the list twice.
 */
uses(TestCase::class);

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

// --- The content identity (S11) ------------------------------------------------------------------

it('normalises a headline to the words that identify the story', function (): void {
    expect($this->normaliser->normalisedHeadline('Acme raises a $40m Series A in Dubai'))
        ->toBe('acme raises 40m series dubai');
});

it('strips the outlet name hung off the end of a headline', function (): void {
    // A pipe or a long dash is read as an outlet suffix wherever it appears last, because a
    // headline almost never contains one.
    expect($this->normaliser->normalisedHeadline('Acme raises $40m | Gulf News'))
        ->toBe('acme raises 40m')
        ->and($this->normaliser->normalisedHeadline('Acme raises $40m — Gulf News'))
        ->toBe('acme raises 40m');
});

it('only strips a hyphenated tail when it is the source’s own name', function (): void {
    // "Acme - a Dubai firm - was sold" is one headline, so a hyphen is ordinary English until the
    // tail matches the outlet we were told published it.
    expect($this->normaliser->normalisedHeadline('Acme raises $40m - Gulf News', 'Gulf News'))
        ->toBe('acme raises 40m')
        ->and($this->normaliser->normalisedHeadline('Acme - a Dubai firm - raises $40m'))
        ->toBe('acme dubai firm raises 40m');
});

it('keeps a non-Latin headline rather than reducing it to nothing', function (): void {
    // The EN/AR promise reaches the hashing too: a Unicode-blind normaliser would give every
    // Arabic headline the same empty identity, which is the one way two articles must never merge.
    expect($this->normaliser->normalisedHeadline('أكمي تجمع ٤٠ مليون دولار'))->not->toBe('');
});

it('has no content hash for a headline with nothing identifying in it', function (): void {
    expect($this->normaliser->contentHash('— | ·'))->toBeNull()
        ->and($this->normaliser->contentHash('the and of'))->toBeNull();
});

it('treats two outlets reporting one event as two articles', function (): void {
    // Deliberate: each is a real article with a real link worth reading. Grouping them into one
    // card is a different question, answered by `SignalClusterer`.
    expect($this->normaliser->contentHash('Acme raises $40m', 'Gulf News'))
        ->not->toBe($this->normaliser->contentHash('Acme raises $40m', 'Khaleej Times'));
});

it('gives one content hash across cosmetic rewording of the same headline', function (): void {
    expect($this->normaliser->contentHash('Acme raises the $40m Series A', 'Gulf News'))
        ->toBe($this->normaliser->contentHash('Acme raises $40m Series A | Gulf News', 'Gulf News'));
});

// --- Redirectors --------------------------------------------------------------------------------

it('recognises a Google redirect by host and path', function (): void {
    expect($this->normaliser->isRedirector('https://google.com/goto?url=OPAQUE'))->toBeTrue()
        ->and($this->normaliser->isRedirector('https://www.google.com/goto?url=OPAQUE'))->toBeTrue()
        ->and($this->normaliser->isRedirector('https://news.google.com/articles/abc'))->toBeTrue()
        // `google.com` also serves plenty that is not a redirect, so the path matters.
        ->and($this->normaliser->isRedirector('https://google.com/search?q=acme'))->toBeFalse()
        ->and($this->normaliser->isRedirector('https://gulfbusiness.com/acme'))->toBeFalse();
});

it('dedupes an ordinary publisher link by its URL', function (): void {
    expect($this->normaliser->dedupeHash('https://gulfbusiness.com/acme', 'Acme raises $40m', 'Gulf Business'))
        ->toBe($this->normaliser->urlHash('https://gulfbusiness.com/acme'));
});

it('dedupes a redirected link by its content, so a varying token is one article', function (): void {
    // The defect this closes: the token is Google's own encoding and cannot be unwrapped locally,
    // so a token that changes between requests used to re-admit the same article on every run.
    $first = $this->normaliser->dedupeHash(
        'https://google.com/goto?url=TOKEN-ONE',
        'Acme raises $40m',
        'Construction Week Online',
    );
    $second = $this->normaliser->dedupeHash(
        'https://google.com/goto?url=TOKEN-TWO',
        'Acme raises $40m',
        'Construction Week Online',
    );

    expect($first)->toBe($second)
        ->and($first)->not->toBe($this->normaliser->urlHash('https://google.com/goto?url=TOKEN-ONE'));
});

it('falls back to the URL hash for a redirected link with no usable headline', function (): void {
    // A worse identity than the content hash, but a real one - and better than no row at all.
    $url = 'https://google.com/goto?url=TOKEN';

    expect($this->normaliser->dedupeHash($url, '— —', 'Some Outlet'))
        ->toBe($this->normaliser->urlHash($url));
});

it('has no dedupe hash at all for a link it cannot read', function (): void {
    expect($this->normaliser->dedupeHash('javascript:alert(1)', 'Acme raises $40m'))->toBeNull();
});

it('takes the redirector list from config, so a new aggregator is a line in a file', function (): void {
    config()->set('signals.redirect_hosts', ['aggregator.test/out']);

    expect($this->normaliser->isRedirector('https://aggregator.test/out?u=1'))->toBeTrue()
        ->and($this->normaliser->isRedirector('https://google.com/goto?url=X'))->toBeFalse();
});
