<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Actions\ResolveWatchlistCompanyNumbersAction;
use App\Modules\Signals\Contracts\SignalSourceProvider;
use App\Modules\Signals\Data\ExtractedSignal;
use App\Modules\Signals\Data\SignalQueryEntity;
use App\Modules\Signals\Data\SignalSourceQuery;
use App\Modules\Signals\Enums\SearchRegion;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Enums\WatchlistEntityType;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Exceptions\SourceAddressNotFound;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use App\Modules\Signals\Services\SignalSourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Companies House as a signal source (S13b, ADR 0023 §6).
 *
 * **The fixtures are the shapes the live registry actually returned.** The S13b spike pulled real
 * filing history for Greggs, M&S and Rolls-Royce; the `description_values` payloads below are
 * copied from it, including the exact `AP01`/`TM01` form codes and the field names the registry
 * uses. That matters more here than for a search source: the whole claim of this source is that the
 * registry has already parsed the event, so a double that invented a tidier shape would be testing
 * a parser the product never meets.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('lead-sourcing.providers.companies_house.api_key', 'ch-test-key');
    config()->set('lead-sourcing.providers.companies_house.base_url', 'https://api.company-information.service.gov.uk');
    config()->set('lead-sourcing.providers.companies_house.record_base_url', 'https://find-and-update.company-information.service.gov.uk');
    config()->set('signals.sources.companies_house.recency_days', 30);
    Http::preventStrayRequests();
    Cache::flush();
});

function registrySource(): SignalSourceProvider
{
    return app(SignalSourceRegistry::class)->for(SignalSourceKind::CompaniesHouse);
}

/**
 * A query addressed at one company, with the filing map a run would have built.
 *
 * @param  array<string, string>  $filings
 */
function registryQuery(?string $number = '00502851', array $filings = ['ap01' => 'uk_officer_change']): SignalSourceQuery
{
    return new SignalSourceQuery(
        eventKey: '*account',
        q: 'Greggs plc',
        region: SearchRegion::Gb,
        gl: 'gb',
        hl: 'en',
        tbs: 'qdr:w',
        num: 20,
        kind: SignalSourceKind::CompaniesHouse,
        config: ['filings' => $filings],
        entity: new SignalQueryEntity(
            type: WatchlistEntityType::Company,
            names: ['Greggs plc'],
            entryId: 1,
            companyNumber: $number,
        ),
    );
}

/** One real `AP01` item, as the registry returned it in the spike. */
function ap01(?string $date = null): array
{
    return [
        'transaction_id' => 'MzU1MzExNjc0MmFkaXF6a2N4',
        'barcode' => 'XFDLPVDS',
        'type' => 'AP01',
        'date' => $date ?? now()->subDays(3)->toDateString(),
        'category' => 'officers',
        'subcategory' => 'appointments',
        'description' => 'appoint-person-director-company-with-name-date',
        'description_values' => [
            'officer_name' => 'Mr Benjamin Jay Waldron',
            'appointment_date' => $date ?? now()->subDays(3)->toDateString(),
        ],
        'action_date' => $date ?? now()->subDays(3)->toDateString(),
        'links' => ['self' => '/company/00502851/filing-history/MzU1MzExNjc0MmFkaXF6a2N4'],
    ];
}

it('turns a filing into an already-classified item, with no AI call', function (): void {
    Http::fake([
        'api.company-information.service.gov.uk/*' => Http::response([
            'filing_history_status' => 'filing-history-available',
            'total_count' => 1,
            'items' => [ap01()],
        ]),
    ]);

    $items = registrySource()->fetch(registryQuery());

    expect($items)->toHaveCount(1);

    $item = $items[0];

    // **The point of the whole source**: it arrives classified.
    expect($item->isClassified())->toBeTrue()
        ->and($item->classification)->toBeInstanceOf(ExtractedSignal::class)
        ->and($item->kind)->toBe(SignalSourceKind::CompaniesHouse);

    $event = $item->classification;

    expect($event->eventKey)->toBe('uk_officer_change')
        // Read out of `description_values` — the registry's own extraction, not a parse of prose.
        ->and($event->subjectPersonName)->toBe('Mr Benjamin Jay Waldron')
        // From the **form code**: AP01 is a director and that is a fact about the form.
        ->and($event->subjectPersonRole)->toBe('Director')
        ->and($event->subjectCompanyName)->toBe('Greggs plc')
        // A registry fact, so both scores are asserted at 100 (ADR 0023 §6).
        ->and($event->confidence)->toBe(100)
        ->and($event->relevance)->toBe(100)
        // Companies House is the UK registry, so the jurisdiction cannot be unresolvable — which
        // matters, because this column decides a converted lead's outreach restriction.
        ->and($event->location)->toBe('United Kingdom');

    expect($item->title)->toBe('Greggs plc — director appointed: Mr Benjamin Jay Waldron');
});

it('dedupes on the transaction id rather than the link', function (): void {
    // Every filing for one company shares the same record page, so the URL is not an identity here.
    // The registry's `transaction_id` is: stable, unique, and immune to the redirect problem that
    // made S11 invent a content hash.
    $second = ap01();
    $second['transaction_id'] = 'a-different-transaction';
    $second['description_values']['officer_name'] = 'Ms Someone Else';

    Http::fake([
        'api.company-information.service.gov.uk/*' => Http::response([
            'items' => [ap01(), $second, ap01()],
        ]),
    ]);

    $items = registrySource()->fetch(registryQuery());

    // Three items in, two distinct transactions out — and the two share a `url_hash`.
    expect($items)->toHaveCount(2)
        ->and($items[0]->urlHash)->toBe($items[1]->urlHash)
        ->and($items[0]->dedupeKey())->not->toBe($items[1]->dedupeKey());
});

it('ignores a filing no signal type asked for', function (): void {
    Http::fake([
        'api.company-information.service.gov.uk/*' => Http::response([
            'items' => [
                ap01(),
                [
                    'transaction_id' => 'accounts-one',
                    'type' => 'AA',
                    'date' => now()->subDay()->toDateString(),
                    'action_date' => now()->subDay()->toDateString(),
                    'category' => 'accounts',
                    'description' => 'accounts-with-accounts-type-group',
                ],
            ],
        ]),
    ]);

    // The map named `ap01` only. An accounts filing is a real filing and not an event this team
    // watches for, so it is dropped before anything else is computed.
    $items = registrySource()->fetch(registryQuery());

    expect($items)->toHaveCount(1)
        ->and($items[0]->classification->eventKey)->toBe('uk_officer_change');
});

it('matches a filing by category when no form code is mapped', function (): void {
    Http::fake([
        'api.company-information.service.gov.uk/*' => Http::response(['items' => [ap01()]]),
    ]);

    // Most specific first: a team can map the whole `officers` category rather than each form, and
    // `officers/appointments` would beat it if both were declared.
    $items = registrySource()->fetch(registryQuery(filings: ['officers' => 'uk_officer_change']));

    expect($items)->toHaveCount(1)
        ->and($items[0]->classification->eventKey)->toBe('uk_officer_change');

    $items = registrySource()->fetch(registryQuery(filings: [
        'officers' => 'broad_type',
        'officers/appointments' => 'specific_type',
    ]));

    expect($items[0]->classification->eventKey)->toBe('specific_type');
});

it('drops a filing older than its own recency window', function (): void {
    Http::fake([
        'api.company-information.service.gov.uk/*' => Http::response([
            'items' => [ap01(now()->subDays(400)->toDateString())],
        ]),
    ]);

    // A filing history goes back decades. Without the window, switching this source on would mint a
    // signal for every appointment since the company was founded — the first run of a watch is what
    // this number protects.
    expect(registrySource()->fetch(registryQuery()))->toBe([]);
});

it('uses the action date rather than the filing date, where they differ', function (): void {
    $item = ap01();
    $item['date'] = now()->subDays(2)->toDateString();          // when it was filed
    $item['action_date'] = now()->subDays(20)->toDateString();  // when it happened

    Http::fake(['api.company-information.service.gov.uk/*' => Http::response(['items' => [$item]])]);

    $items = registrySource()->fetch(registryQuery());

    // A member reading "appointed a director" means the appointment, not the paperwork.
    expect($items[0]->publishedAt->toDateString())->toBe(now()->subDays(20)->toDateString());
});

it('sends nothing at all when the account has no company number', function (): void {
    Http::fake(['api.company-information.service.gov.uk/*' => Http::response(['items' => [ap01()]])]);

    // A person, an account not on the UK register, or one the resolver has not reached. All three
    // are ordinary states, and none is worth a request to confirm.
    expect(registrySource()->fetch(registryQuery(number: null)))->toBe([]);

    Http::assertNothingSent();
});

it('sends nothing when the run offered no registry-mapped types', function (): void {
    Http::fake(['api.company-information.service.gov.uk/*' => Http::response(['items' => [ap01()]])]);

    expect(registrySource()->fetch(registryQuery(filings: [])))->toBe([]);

    Http::assertNothingSent();
});

it('authenticates the way Lead Sourcing does, against the same credential', function (): void {
    Http::fake(['api.company-information.service.gov.uk/*' => Http::response(['items' => []])]);

    registrySource()->fetch(registryQuery());

    Http::assertSent(function ($request): bool {
        // Basic auth, key as the username and an empty password — the registry's scheme, and what
        // `CompaniesHouseProvider` already does. ADR 0023: do not open a second integration.
        $expected = 'Basic '.base64_encode('ch-test-key:');

        return str_contains($request->url(), '/company/00502851/filing-history')
            && $request->header('Authorization')[0] === $expected;
    });
});

it('is free, which is the other half of why it skips the AI', function (): void {
    expect(registrySource()->estimatedCostMicros(40))->toBe(0);
});

it('fails on a 200 whose body is not the expected shape', function (): void {
    Http::fake(['api.company-information.service.gov.uk/*' => Http::response('<html>sign in</html>')]);

    expect(fn () => registrySource()->fetch(registryQuery()))->toThrow(SignalsException::class);
});

// **The S13b review's finding 1, and the worst bug the reviews found.** `/filing-history` answers
// 404 for a number that is not on the register, one struck off, and one simply malformed — and a
// number reaches here from `leads.company_number`, which no validation stands behind. It used to
// arrive as `sourceFailed()`, which fails the **whole run**: the news queries on the same watch
// discarded, their cost kept, and the member told the registry was broken. Worse, it did so for
// ever: `entity_cursor` only advances when a run completes, so the dead account stayed at the head
// of the ring and was the first thing asked every time. One stale lead number was enough to stop a
// 250-account watchlist permanently.
it('treats an unknown company number as a skipped query rather than a failed run', function (): void {
    foreach ([404, 410] as $status) {
        Http::fake(['api.company-information.service.gov.uk/*' => Http::response(['errors' => [['error' => 'company-profile-not-found']]], $status)]);

        $thrown = null;

        try {
            registrySource()->fetch(registryQuery('99999999'));
        } catch (SignalsException $e) {
            $thrown = $e;
        }

        // The **third** meaning of a 4xx (ADR 0023 §4 named two): not the provider's health, not
        // our query's validity, but the addressee's existence. Not retryable, and the breaker is
        // deliberately left shut — a struck-off company says nothing about whether the registry is up.
        expect($thrown?->errorCode())->toBe('signals.source_address_not_found', (string) $status)
            ->and($thrown)->toBeInstanceOf(SourceAddressNotFound::class)
            ->and($thrown?->isRetryable())->toBeFalse();
    }
});

// The other half of the rule: a 4xx that is *not* about the addressee still fails loudly. A 401 is
// our credential and a 403 is our quota, and swallowing either per query is how a run completes
// green having found nothing, every run, for ever.
it('still fails the run on a registry refusal that is about us', function (): void {
    foreach ([401, 403] as $status) {
        Http::fake(['api.company-information.service.gov.uk/*' => Http::response(['error' => 'invalid authorization'], $status)]);

        $thrown = null;

        try {
            registrySource()->fetch(registryQuery());
        } catch (SignalsException $e) {
            $thrown = $e;
        }

        expect($thrown?->errorCode())->toBe('signals.source_failed', (string) $status)
            ->and($thrown)->not->toBeInstanceOf(SourceAddressNotFound::class);
    }
});

it('fails fast and sends nothing when the registry credential is missing', function (): void {
    config()->set('lead-sourcing.providers.companies_house.api_key', '');
    Http::fake(['api.company-information.service.gov.uk/*' => Http::response(['items' => []])]);

    $thrown = null;

    try {
        registrySource()->fetch(registryQuery());
    } catch (SignalsException $e) {
        $thrown = $e;
    }

    expect($thrown?->errorCode())->toBe('signals.source_misconfigured');
    Http::assertNothingSent();
});

// --- Resolving a watchlist entry to a company number -----------------------------------------

it('takes the company number from the team’s own lead, with no request', function (): void {
    $team = Team::factory()->create();

    [$entry] = app(TeamContext::class)->run($team, function () use ($team): array {
        $lead = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'company_name' => 'Greggs plc',
            'company_number' => '00502851',
        ]);

        $list = SignalWatchlist::factory()->create(['team_id' => $team->getKey()]);

        return [SignalWatchlistEntry::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'entity_type' => WatchlistEntityType::Company->value,
            'name' => 'Greggs plc',
            'lead_id' => $lead->getKey(),
        ])];
    });

    Http::fake(['api.company-information.service.gov.uk/*' => Http::response(['items' => []])]);

    $resolved = app(TeamContext::class)->run($team, fn (): int => app(ResolveWatchlistCompanyNumbersAction::class)
        ->handle((int) $team->getKey(), [$entry]));

    expect($resolved)->toBe(1)
        ->and($entry->refresh()->company_number)->toBe('00502851');

    // A pipeline watchlist mirrors leads, and a Companies-House-discovered lead already knows its
    // number — so the common case costs nothing.
    Http::assertNothingSent();
});

it('accepts a searched number only on an exact name match', function (): void {
    $team = Team::factory()->create();

    $entry = app(TeamContext::class)->run($team, function () use ($team): SignalWatchlistEntry {
        $list = SignalWatchlist::factory()->create(['team_id' => $team->getKey()]);

        return SignalWatchlistEntry::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'entity_type' => WatchlistEntityType::Company->value,
            'name' => 'Acme',
            'lead_id' => null,
        ]);
    });

    // Companies House search is fuzzy and ranked. The top hit is a *different* company whose name
    // merely contains the one asked for — exactly the "Acme" / "Acme Holdings Ltd" confusion
    // ADR 0021 records, and here there is no visible confidence and no _Convert anyway_ button to
    // soften it: the number silently decides whose filings become somebody's signals.
    Http::fake([
        'api.company-information.service.gov.uk/search/companies*' => Http::response([
            'items' => [
                ['title' => 'ACME HOLDINGS LTD', 'company_number' => '11111111', 'company_status' => 'active'],
                ['title' => 'ACME GROUP LIMITED', 'company_number' => '22222222', 'company_status' => 'active'],
            ],
        ]),
    ]);

    $resolved = app(TeamContext::class)->run($team, fn (): int => app(ResolveWatchlistCompanyNumbersAction::class)
        ->handle((int) $team->getKey(), [$entry]));

    $entry->refresh();

    expect($resolved)->toBe(0)
        ->and($entry->company_number)->toBeNull()
        // Stamped anyway: "asked, nothing matched exactly" is the negative cache, and without it
        // this search would run again on every run for ever.
        ->and($entry->company_number_checked_at)->not->toBeNull();
});

it('accepts the exact match when the registry returns one', function (): void {
    $team = Team::factory()->create();

    $entry = app(TeamContext::class)->run($team, function () use ($team): SignalWatchlistEntry {
        $list = SignalWatchlist::factory()->create(['team_id' => $team->getKey()]);

        return SignalWatchlistEntry::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'entity_type' => WatchlistEntityType::Company->value,
            'name' => 'Acme Trading Ltd',
            'lead_id' => null,
        ]);
    });

    Http::fake([
        'api.company-information.service.gov.uk/search/companies*' => Http::response([
            'items' => [
                ['title' => 'ACME HOLDINGS LTD', 'company_number' => '11111111', 'company_status' => 'active'],
                ['title' => 'Acme Trading Ltd', 'company_number' => '33333333', 'company_status' => 'active'],
            ],
        ]),
    ]);

    app(TeamContext::class)->run($team, fn (): int => app(ResolveWatchlistCompanyNumbersAction::class)
        ->handle((int) $team->getKey(), [$entry]));

    // Not the top hit — the one whose normalised name is the one asked for.
    expect($entry->refresh()->company_number)->toBe('33333333');
});

it('never searches the registry for a person', function (): void {
    $team = Team::factory()->create();

    $entry = app(TeamContext::class)->run($team, function () use ($team): SignalWatchlistEntry {
        $list = SignalWatchlist::factory()->create(['team_id' => $team->getKey()]);

        return SignalWatchlistEntry::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'entity_type' => WatchlistEntityType::Person->value,
            'name' => 'Jane Founder',
            'lead_id' => null,
        ]);
    });

    Http::fake(['api.company-information.service.gov.uk/*' => Http::response(['items' => []])]);

    app(TeamContext::class)->run($team, fn (): int => app(ResolveWatchlistCompanyNumbersAction::class)
        ->handle((int) $team->getKey(), [$entry]));

    // Companies House indexes companies; an officer appears only inside a company's own record.
    Http::assertNothingSent();
    expect($entry->refresh()->company_number_checked_at)->toBeNull();
});

it('does not re-ask the registry about an entry it has already checked', function (): void {
    $team = Team::factory()->create();

    $entry = app(TeamContext::class)->run($team, function () use ($team): SignalWatchlistEntry {
        $list = SignalWatchlist::factory()->create(['team_id' => $team->getKey()]);

        return SignalWatchlistEntry::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'entity_type' => WatchlistEntityType::Company->value,
            'name' => 'Not On The Register Ltd',
            'lead_id' => null,
            'company_number' => null,
            'company_number_checked_at' => now()->subDay(),
        ]);
    });

    Http::fake(['api.company-information.service.gov.uk/*' => Http::response(['items' => []])]);

    app(TeamContext::class)->run($team, fn (): int => app(ResolveWatchlistCompanyNumbersAction::class)
        ->handle((int) $team->getKey(), [$entry]));

    // Most manual entries never resolve — a trading name, an overseas business — and re-asking on
    // every run for ever is the cost the timestamp exists to avoid.
    Http::assertNothingSent();
});

it('leaves an entry unstamped when the registry could not be reached', function (): void {
    $team = Team::factory()->create();

    $entry = app(TeamContext::class)->run($team, function () use ($team): SignalWatchlistEntry {
        $list = SignalWatchlist::factory()->create(['team_id' => $team->getKey()]);

        return SignalWatchlistEntry::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'entity_type' => WatchlistEntityType::Company->value,
            'name' => 'Acme Trading Ltd',
            'lead_id' => null,
        ]);
    });

    Http::fake(['api.company-information.service.gov.uk/*' => Http::response('', 503)]);

    app(TeamContext::class)->run($team, fn (): int => app(ResolveWatchlistCompanyNumbersAction::class)
        ->handle((int) $team->getKey(), [$entry]));

    $entry->refresh();

    // A transport failure is not an answer. Stamping it would cache "not found" for an account that
    // is on the register, so the entry stays unstamped and the next run tries again.
    expect($entry->company_number)->toBeNull()
        ->and($entry->company_number_checked_at)->toBeNull();
});

it('bounds how many names it looks up in one run', function (): void {
    config()->set('signals.sources.companies_house.resolve_per_run', 2);

    $team = Team::factory()->create();

    $entries = app(TeamContext::class)->run($team, function () use ($team): array {
        $list = SignalWatchlist::factory()->create(['team_id' => $team->getKey()]);

        return array_map(
            fn (int $i): SignalWatchlistEntry => SignalWatchlistEntry::factory()->create([
                'team_id' => $team->getKey(),
                'signal_watchlist_id' => $list->getKey(),
                'entity_type' => WatchlistEntityType::Company->value,
                'name' => 'Company Number '.$i.' Ltd',
                'lead_id' => null,
            ]),
            range(1, 5),
        );
    });

    Http::fake([
        'api.company-information.service.gov.uk/search/companies*' => Http::response(['items' => []]),
    ]);

    app(TeamContext::class)->run($team, fn (): int => app(ResolveWatchlistCompanyNumbersAction::class)
        ->handle((int) $team->getKey(), $entries));

    // A freshly pasted list of 250 names would otherwise make 250 registry searches in one go. The
    // rest resolve on later runs.
    Http::assertSentCount(2);

    $unstamped = collect($entries)->filter(fn (SignalWatchlistEntry $e): bool => $e->refresh()->company_number_checked_at === null);

    // And the ones it did not reach are left unstamped, so a later run still asks about them.
    expect($unstamped)->toHaveCount(3);
});
