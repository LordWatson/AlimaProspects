<?php

declare(strict_types=1);

use App\Modules\AiInsights\Data\ActivitySnapshot;

it('calls a week with nothing in it quiet, so no model is paid to say so', function (): void {
    $snapshot = new ActivitySnapshot('2026-10-23', '2026-10-30', [
        'leads' => 0,
        'contacted' => 0,
        'replied' => 0,
    ]);

    expect($snapshot->isQuiet())->toBeTrue();
});

it('is not quiet when a single figure moved', function (): void {
    $snapshot = new ActivitySnapshot('2026-10-23', '2026-10-30', [
        'leads' => 0,
        'contacted' => 1,
    ]);

    expect($snapshot->isQuiet())->toBeFalse();
});

it('is not quiet when a busy week collapsed to nothing', function (): void {
    $snapshot = new ActivitySnapshot('2026-10-23', '2026-10-30', [
        'leads' => 0,
        'leads_change' => -12,
        'contacted' => 0,
    ]);

    // The week every team most needs explaining: plenty last week, nothing this week. Under a
    // `> 0` test every figure reads as zero-or-less, the week looks quiet, and the model is never
    // asked about the one thing that actually happened — the work stopped.
    expect($snapshot->isQuiet())->toBeFalse();
});

it('does not let a standing total alone buy an AI call', function (): void {
    $snapshot = new ActivitySnapshot('2026-10-23', '2026-10-30', [
        'leads' => 0,
        'contacted' => 0,
        // Three tasks have been overdue for months. The account is dormant; it would otherwise pay
        // for a model to say so every morning.
        'open_tasks_overdue' => 3,
    ]);

    expect($snapshot->isQuiet())->toBeTrue();
});

it('treats a snapshot with no figures at all as quiet', function (): void {
    expect((new ActivitySnapshot(null, null, []))->isQuiet())->toBeTrue();
});

it('renders the prompt value as figures only, with no team-entered text', function (): void {
    $snapshot = new ActivitySnapshot('2026-10-23', '2026-10-30', [
        'leads' => 4,
        'leads_change' => -2,
        'replied' => 1,
    ], [
        'email' => ['attempts' => 9, 'replies' => 1, 'reply_rate_percent' => 11.1],
    ]);

    $value = $snapshot->toPromptValue();
    $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['period'])->toBe(['from' => '2026-10-23', 'to' => '2026-10-30'])
        ->and($decoded['metrics']['leads'])->toBe(4)
        // A negative change is information, not an error: "fewer than last week" is half the point.
        ->and($decoded['metrics']['leads_change'])->toBe(-2)
        ->and($decoded['by_channel']['email']['reply_rate_percent'])->toBe(11.1);
});

it('renders an identical week identically, so the response cache can hit', function (): void {
    $metrics = ['leads' => 4, 'replied' => 1];

    $first = new ActivitySnapshot('2026-10-23', '2026-10-30', $metrics);
    $second = new ActivitySnapshot('2026-10-23', '2026-10-30', $metrics);

    // The AI Platform keys its cache on a hash of the normalised input (see its module doc), so a
    // snapshot that serialised differently each time would pay for every identical call.
    expect($first->toPromptValue())->toBe($second->toPromptValue());
});
