<?php

declare(strict_types=1);

use App\Modules\Signals\Data\SignalQuery;
use App\Modules\Signals\Enums\SearchRegion;
use App\Modules\Signals\Services\SignalItemNormaliser;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use App\Modules\Signals\Testing\FakeNewsSearchClient;
use Tests\TestCase;

/*
 * Boots the application, which {@see SignalItemNormaliser} needs since S11: the redirector list is
 * configuration (`signals.redirect_hosts`), not a constant, so the next aggregator that wraps its
 * links is a line in a file rather than a release. That makes this a unit test of a class that
 * reads config rather than a pure one, which is the honest trade for not holding the list twice.
 */
uses(TestCase::class);

function fakeQuery(int $num = 20): SignalQuery
{
    return new SignalQuery(
        eventKey: SignalPresetCatalogue::FUNDING_ROUND,
        region: SearchRegion::Ae,
        q: 'Dubai startup raises funding',
        gl: 'ae',
        hl: 'en',
        tbs: 'qdr:w',
        num: $num,
    );
}

it('builds items whose url_hash matches the normaliser', function (): void {
    $fake = new FakeNewsSearchClient;
    $item = $fake->item('Deal', 'https://arabianbusiness.com/deal');

    expect($item->urlHash)->toBe((new SignalItemNormaliser)->urlHash('https://arabianbusiness.com/deal'));
});

it('honours the query num and records the queries it was asked', function (): void {
    $fake = new FakeNewsSearchClient;
    $fake->willReturn($fake->dubaiFixtures()); // 3 items

    $one = $fake->search(fakeQuery(num: 1));
    $all = $fake->search(fakeQuery(num: 20));

    expect($one)->toHaveCount(1)
        ->and($all)->toHaveCount(3)
        ->and($fake->queries())->toHaveCount(2);
});

it('throws the configured failure', function (): void {
    $fake = (new FakeNewsSearchClient)->willFail(new RuntimeException('boom'));

    expect(fn () => $fake->search(fakeQuery()))->toThrow(RuntimeException::class);
});
