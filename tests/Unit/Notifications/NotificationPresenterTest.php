<?php

declare(strict_types=1);

use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationPresenter;

/**
 * What a notification says, given a payload.
 *
 * The two rules the copy follows: **say the number** (a count is worth reading; "new matches found"
 * makes a member open the app to learn whether it was worth it), and **never a bare noun where a
 * name fits**.
 *
 * Everything here is a translation key plus params — never a finished sentence (ADR 0007).
 */
it('returns keys and params, not prose', function (): void {
    $content = (new NotificationPresenter)->present(NotificationType::TopLeads, [
        'count' => 4,
        'names' => ['Acme Ltd', 'Bright Bean', 'Third Co', 'Fourth Co'],
    ]);

    expect($content->title)->toBe(':count strong new matches')
        ->and($content->params['count'])->toBe(4)
        // The row is read days later, possibly after the app was switched to Arabic.
        ->and($content->toStored()['type'])->toBe('top_leads');
});

it('names what it can and counts the rest', function (
    int $count,
    array $names,
    string $expectedList,
    int $expectedOthers,
    string $expectedBody,
): void {
    $content = (new NotificationPresenter)->present(NotificationType::TopLeads, [
        'count' => $count,
        'names' => $names,
    ]);

    // The joining word is in the *key*, not in the params: the params are stored on the row, and a
    // notification written in English must not keep an English "and" when read in Arabic.
    expect($content->params['names'])->toBe($expectedList)
        ->and($content->params['others'])->toBe($expectedOthers)
        ->and($content->body)->toBe($expectedBody);
})->with([
    'one' => [1, ['Acme Ltd'], 'Acme Ltd', 0, 'Including :names.'],
    'two' => [2, ['Acme Ltd', 'Bright Bean'], 'Acme Ltd, Bright Bean', 0, 'Including :names.'],
    // Two named and the rest counted: enough to tell a member whether to stop what they are doing.
    'several' => [6, ['Acme Ltd', 'Bright Bean', 'Third Co'], 'Acme Ltd, Bright Bean', 4, 'Including :names and :others others.'],
    'none' => [3, [], '', 0, 'Alima graded :count companies as a strong fit for you.'],
]);

it('says a search found nothing, because that is the useful part', function (): void {
    $presenter = new NotificationPresenter;

    expect($presenter->present(NotificationType::DiscoveryComplete, ['leads_created' => 0, 'run_id' => 7])->title)
        ->toBe('Your search found nothing new')
        ->and($presenter->present(NotificationType::DiscoveryComplete, ['leads_created' => 5, 'run_id' => 7])->title)
        ->toBe('Your search is done');
});

it('links to the thing it is about', function (): void {
    $presenter = new NotificationPresenter;

    expect($presenter->present(NotificationType::DiscoveryComplete, ['run_id' => 7, 'leads_created' => 2])->url)
        ->toBe('/discovery/7')
        ->and($presenter->present(NotificationType::TaskDue, ['lead_id' => 42, 'title' => 'Call'])->url)
        ->toBe('/leads/42')
        ->and($presenter->present(NotificationType::SignalsDetected, ['count' => 2])->url)
        ->toBe('/signals')
        // Nothing to link to without the id, rather than a link to nowhere.
        ->and($presenter->present(NotificationType::TaskDue, ['title' => 'Call'])->url)
        ->toBeNull();
});

it('attributes an assignment to the colleague who made it, and copes when they are gone', function (): void {
    $presenter = new NotificationPresenter;

    expect($presenter->present(NotificationType::LeadAssigned, ['actor' => 'Dana', 'company' => 'Acme Ltd'])->body)
        ->toBe(':actor gave you :company.')
        // Their account has since been removed: a different sentence, not a dangling name.
        ->and($presenter->present(NotificationType::LeadAssigned, ['actor' => '', 'company' => 'Acme Ltd'])->body)
        ->toBe('You are now working :company.');
});

it('clamps a long company name so it survives a lock screen', function (): void {
    $content = (new NotificationPresenter)->present(NotificationType::TaskDue, [
        'title' => str_repeat('Chase them about the thing ', 20),
        'company' => str_repeat('A Very Long Company Name ', 20),
    ]);

    expect(mb_strlen((string) $content->params['task']))->toBeLessThanOrEqual(60)
        ->and(mb_strlen((string) $content->params['company']))->toBeLessThanOrEqual(60);
});

it('copes with a payload that is missing or malformed', function (): void {
    $presenter = new NotificationPresenter;

    // A notification raised by a producer that forgot a field must still render something, rather
    // than taking down the bell for every other row on the page.
    foreach (NotificationType::cases() as $type) {
        $content = $presenter->present($type, []);

        expect($content->title)->not->toBe('')
            ->and($content->body)->not->toBe('');
    }

    // `names` as a string rather than a list, which only a bug would produce.
    expect($presenter->present(NotificationType::TopLeads, ['count' => 2, 'names' => 'Acme'])->params['names'])
        ->toBe('')
        ->and($presenter->present(NotificationType::TopLeads, ['count' => 2, 'names' => 'Acme'])->params['others'])
        ->toBe(0);
});

it('distinguishes a spent allowance from a low one', function (): void {
    $presenter = new NotificationPresenter;

    expect($presenter->present(NotificationType::CreditLow, ['remaining_percent' => 0])->title)
        ->toBe('AI credit has run out')
        ->and($presenter->present(NotificationType::CreditLow, ['remaining_percent' => 8])->title)
        ->toBe('AI credit is running low');
});
