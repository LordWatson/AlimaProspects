<?php

declare(strict_types=1);

use App\Modules\Core\Enums\Currency;
use App\Modules\Signals\Data\ExtractedSignal;
use App\Modules\Signals\Enums\SignalEventType;

function aiItem(array $overrides = []): array
{
    return array_merge([
        'item_index' => 0,
        'is_event' => true,
        'event_type' => 'funding_round',
        'subject_person_name' => 'Jane Founder',
        'subject_person_role' => 'CEO',
        'subject_company_name' => 'Acme DMCC',
        'counterparty_name' => null,
        'amount' => 40_000_000,
        'currency' => 'USD',
        'location' => 'Dubai',
        'confidence' => 90,
        'relevance' => 80,
        'summary' => 'Acme raised a Series A.',
        'suggested_approach' => 'Intro via LinkedIn.',
    ], $overrides);
}

it('maps an AI item, converting amount to minor units and resolving the currency', function (): void {
    $extracted = ExtractedSignal::fromAiItem(aiItem());

    expect($extracted->eventType)->toBe(SignalEventType::FundingRound)
        ->and($extracted->currency)->toBe(Currency::Usd)
        ->and($extracted->amountMinor)->toBe(4_000_000_000)
        ->and($extracted->confidence)->toBe(90)
        ->and($extracted->relevance)->toBe(80);
});

it('drops the amount when the currency is missing or unsupported', function (): void {
    expect(ExtractedSignal::fromAiItem(aiItem(['currency' => null]))->amountMinor)->toBeNull()
        ->and(ExtractedSignal::fromAiItem(aiItem(['currency' => 'XYZ']))->amountMinor)->toBeNull()
        ->and(ExtractedSignal::fromAiItem(aiItem(['currency' => 'XYZ']))->currency)->toBeNull();
});

it('clamps scores into 0–100 and the summary length', function (): void {
    $extracted = ExtractedSignal::fromAiItem(aiItem([
        'confidence' => 250,
        'relevance' => -5,
        'summary' => str_repeat('x', 900),
    ]));

    expect($extracted->confidence)->toBe(100)
        ->and($extracted->relevance)->toBe(0)
        ->and(mb_strlen($extracted->summary))->toBeLessThanOrEqual(500);
});

it('nulls an unknown event type', function (): void {
    expect(ExtractedSignal::fromAiItem(aiItem(['event_type' => 'ipo']))->eventType)->toBeNull();
});

it('enforces a LinkedIn/referral next step, replacing any contact-channel suggestion', function (): void {
    $default = 'Introduce yourself via LinkedIn or a mutual referral.';

    expect(ExtractedSignal::fromAiItem(aiItem(['suggested_approach' => 'Email them at jane@acme.com']))->suggestedApproach)->toBe($default)
        ->and(ExtractedSignal::fromAiItem(aiItem(['suggested_approach' => 'Cold call the office']))->suggestedApproach)->toBe($default)
        ->and(ExtractedSignal::fromAiItem(aiItem(['suggested_approach' => null]))->suggestedApproach)->toBe($default)
        ->and(ExtractedSignal::fromAiItem(aiItem(['suggested_approach' => 'Ask a mutual connection for a warm intro on LinkedIn.']))->suggestedApproach)
        ->toBe('Ask a mutual connection for a warm intro on LinkedIn.');
});

it('strips a leaked email from stored text fields (data minimisation)', function (): void {
    $extracted = ExtractedSignal::fromAiItem(aiItem(['subject_person_name' => 'Jane Founder jane@acme.com']));

    expect($extracted->subjectPersonName)->not->toContain('@')
        ->and($extracted->subjectPersonName)->toContain('Jane Founder');
});

it('drops an absurd amount rather than overflowing', function (): void {
    expect(ExtractedSignal::fromAiItem(aiItem(['amount' => 9.9e18]))->amountMinor)->toBeNull();
});

it('only keeps a genuine, typed, in-threshold event', function (): void {
    expect(ExtractedSignal::fromAiItem(aiItem())->meetsThreshold(60, 40))->toBeTrue()
        ->and(ExtractedSignal::fromAiItem(aiItem(['is_event' => false]))->meetsThreshold(60, 40))->toBeFalse()
        ->and(ExtractedSignal::fromAiItem(aiItem(['event_type' => null]))->meetsThreshold(60, 40))->toBeFalse()
        ->and(ExtractedSignal::fromAiItem(aiItem(['confidence' => 50]))->meetsThreshold(60, 40))->toBeFalse()
        ->and(ExtractedSignal::fromAiItem(aiItem(['relevance' => 30]))->meetsThreshold(60, 40))->toBeFalse();
});
