<?php

declare(strict_types=1);

use App\Modules\Signals\Data\SignalQuery;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Services\SignalItemNormaliser;
use App\Modules\Signals\Testing\FakeNewsSearchClient;

function fakeQuery(int $num = 20): SignalQuery
{
    return new SignalQuery(
        eventType: SignalEventType::FundingRound,
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
