<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Enums\PlatformMetric;
use App\Modules\Admin\Enums\PlatformReport;
use App\Modules\Admin\Models\PlatformDailyMetric;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Services\Platform\PlatformMarginBuilder;
use App\Modules\Admin\Services\Platform\PlatformMetricStore;
use App\Modules\Admin\Services\Platform\PlatformMoney;
use App\Modules\Admin\Services\Platform\PlatformReportBuilder;
use App\Modules\Admin\Support\PlatformWindow;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Reporting\Support\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminFixtures as Fixtures;

uses(RefreshDatabase::class);

/*
 * Platform reporting (Phase 19c) — **the number the business lives on**, and the rules that keep it
 * honest.
 *
 * The margin figure is the one worth testing on fixtures with known costs and a known plan price,
 * because it is the one figure assembled from two different units and two different currencies: a
 * plan's price in minor units and a provider's cost in micro-units. Every assertion below is about
 * a way that could silently go wrong.
 */

/** A window of exactly one day, so the proration arithmetic is readable. */
function oneDay(string $day): DateRange
{
    return DateRange::for(ReportRange::Custom, 'UTC', $day, $day);
}

function metric(PlatformMetric $metric, string $dimension, int $value, string $day): void
{
    PlatformDailyMetric::factory()->metric($metric, $dimension)->on($day)->worth($value)->create();
}

beforeEach(function (): void {
    // A known rate, so the conversion arithmetic in the assertions is the arithmetic in the code
    // rather than a coincidence of whatever the environment configures.
    config()->set('admin.reporting.currency', 'gbp');
    config()->set('admin.reporting.fx', ['gbp' => 100, 'usd' => 80]);
});

it('computes gross margin per tenant from a known price and a known cost', function (): void {
    $day = '2026-10-15';
    [, $team] = Fixtures::tenant('Profitable Ltd');
    $id = (int) $team->getKey();

    // £129.00 a month, as one day's snapshot. Prorated over a one-day window against a nominal
    // 30-day month, so the revenue attributed to the day is 12900 / 30 = 430p.
    metric(PlatformMetric::TeamMrr, 't'.$id.'|'.PlanKey::Team->value, 12_900, $day);

    // $1.00 of provider cost: 1,000,000 micro-units at 80p to the dollar = 80p.
    metric(PlatformMetric::TeamCostMicros, 't'.$id.'|USD', 1_000_000, $day);

    $rows = app(PlatformMarginBuilder::class)->perTeam(oneDay($day));

    expect($rows)->toHaveCount(1);

    $row = $rows[0];

    expect($row->label)->toBe('Profitable Ltd')
        ->and($row->revenue_minor)->toBe(430)
        ->and($row->cost_minor)->toBe(80)
        ->and($row->margin_minor)->toBe(350)
        ->and($row->margin_ratio)->toBeGreaterThan(0.81)
        ->and($row->margin_ratio)->toBeLessThan(0.82)
        ->and($row->isPartial())->toBeFalse();
});

it('shows cost it cannot convert rather than absorbing it', function (): void {
    // The phase's own risk note: a margin that looks authoritative while a provider's cost is
    // unrecorded. An unconfigured currency is reported, not dropped and not treated as sterling.
    $day = '2026-10-15';
    [, $team] = Fixtures::tenant('Mystery Ltd');
    $id = (int) $team->getKey();

    metric(PlatformMetric::TeamMrr, 't'.$id.'|'.PlanKey::Team->value, 12_900, $day);
    metric(PlatformMetric::TeamCostMicros, 't'.$id.'|JPY', 5_000_000, $day);

    $row = app(PlatformMarginBuilder::class)->perTeam(oneDay($day))[0];

    expect($row->cost_minor)->toBe(0)
        ->and($row->unconverted_micros)->toBe(['JPY' => 5_000_000])
        ->and($row->isPartial())->toBeTrue()
        // The margin is therefore a ceiling, which the page says in as many words.
        ->and($row->margin_minor)->toBe(430);
});

it('never spreads unattributed platform spend across tenants', function (): void {
    $day = '2026-10-15';
    [, $team] = Fixtures::tenant('Paying Ltd');
    $id = (int) $team->getKey();

    metric(PlatformMetric::TeamMrr, 't'.$id.'|'.PlanKey::Team->value, 12_900, $day);
    metric(PlatformMetric::TeamCostMicros, 'unattributed|USD', 2_000_000, $day);

    $rows = app(PlatformMarginBuilder::class)->perTeam(oneDay($day));

    $tenant = $rows[array_search('t'.$id, array_column($rows, 'key'), true)];
    $unattributed = $rows[array_search('unattributed', array_column($rows, 'key'), true)];

    expect($tenant->cost_minor)->toBe(0)
        ->and($tenant->margin_minor)->toBe(430)
        // Its own row, last, with no revenue — and never divided across customers, which would
        // flatter every margin equally and be impossible to notice.
        ->and($unattributed->revenue_minor)->toBe(0)
        ->and($unattributed->cost_minor)->toBe(160)
        ->and($unattributed->margin_ratio)->toBeNull();
});

it('leaves a margin ratio empty rather than reporting a free tenant as break-even', function (): void {
    $day = '2026-10-15';
    [, $team] = Fixtures::tenant('Freeloader Ltd');
    $id = (int) $team->getKey();

    // No MRR row at all — a free-plan tenant — but real cost.
    metric(PlatformMetric::TeamCostMicros, 't'.$id.'|USD', 500_000, $day);

    $row = app(PlatformMarginBuilder::class)->perTeam(oneDay($day))[0];

    expect($row->revenue_minor)->toBe(0)
        ->and($row->cost_minor)->toBe(40)
        ->and($row->margin_minor)->toBe(-40)
        // Null, not 0.0: "0%" renders as break-even, which is the opposite of true here.
        ->and($row->margin_ratio)->toBeNull();
});

it('prorates revenue day by day, so a plan change mid-window is accounted for', function (): void {
    [, $team] = Fixtures::tenant('Upgrader Ltd');
    $id = (int) $team->getKey();

    metric(PlatformMetric::TeamMrr, 't'.$id.'|'.PlanKey::Starter->value, 4_900, '2026-10-01');
    metric(PlatformMetric::TeamMrr, 't'.$id.'|'.PlanKey::Scale->value, 37_900, '2026-10-02');

    $window = DateRange::for(ReportRange::Custom, 'UTC', '2026-10-01', '2026-10-02');

    $row = app(PlatformMarginBuilder::class)->perTeam($window)[0];

    // (4900 + 37900) / 30, each day contributing the plan it was actually on.
    expect($row->revenue_minor)->toBe(intdiv(42_800, 30))
        // Reported under the plan that accounts for most of the money.
        ->and($row->plan)->toBe(PlanKey::Scale->value);
});

it('orders tenants worst margin first', function (): void {
    $day = '2026-10-15';
    [, $good] = Fixtures::tenant('Good Ltd');
    [, $bad] = Fixtures::tenant('Bad Ltd');

    metric(PlatformMetric::TeamMrr, 't'.$good->getKey().'|'.PlanKey::Team->value, 12_900, $day);
    metric(PlatformMetric::TeamCostMicros, 't'.$bad->getKey().'|USD', 10_000_000, $day);

    $rows = app(PlatformMarginBuilder::class)->perTeam(oneDay($day));

    expect($rows[0]->label)->toBe('Bad Ltd')
        ->and($rows[1]->label)->toBe('Good Ltd');
});

it('excludes unattributed spend from the per-plan view', function (): void {
    // It belongs to no plan, and adding it to one would make that plan look worse for a reason
    // that has nothing to do with it.
    $day = '2026-10-15';
    [, $team] = Fixtures::tenant('Paying Ltd');

    metric(PlatformMetric::TeamMrr, 't'.$team->getKey().'|'.PlanKey::Team->value, 12_900, $day);
    metric(PlatformMetric::TeamCostMicros, 'unattributed|USD', 2_000_000, $day);

    $rows = app(PlatformMarginBuilder::class)->perPlan(oneDay($day));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->cost_minor)->toBe(0);
});

it('converts micro-units at the configured rate and reports the rates it used', function (): void {
    $money = app(PlatformMoney::class);

    expect($money->currency())->toBe('GBP')
        ->and($money->convert(['usd' => 1_000_000])->minor_units)->toBe(80)
        ->and($money->convert(['USD' => 2_500_000])->minor_units)->toBe(200)
        ->and($money->rates())->toBe(['GBP' => 100, 'USD' => 80]);
});

/*
 * The screen and the export.
 */

it('builds a table for every declared report', function (): void {
    // What makes "any report is exportable" a closed set rather than a promise: a case that cannot
    // be turned into a table does not ship.
    $page = app(PlatformReportBuilder::class)->build(oneDay('2026-10-15'));

    $keys = array_map(static fn (object $table): string => $table->key->value, $page->tables);

    expect($keys)->toEqualCanonicalizing(PlatformReport::values());
});

it('shows reporting to an operator who may read it', function (): void {
    $this->actingAs(Fixtures::operator(AdminRole::Finance));

    $this->get('/admin/reporting')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/reporting/Index')
            ->has('report.kpis')
            ->has('report.funnel', 6)
            ->has('report.tables', count(PlatformReport::cases())));
});

it('refuses reporting to an operator whose role does not carry it', function (): void {
    // There is no role without `reports.read` today, so the gate is exercised by taking the
    // ability away in config — which is where the mapping lives, and the point of its living there.
    config()->set('admin.abilities.'.AdminRole::Support->value, ['platform.access']);

    $this->actingAs(Fixtures::operator(AdminRole::Support));

    $this->get('/admin/reporting')->assertForbidden();
});

it('exports a report as CSV and audits the export', function (): void {
    $day = '2026-10-15';
    [, $team] = Fixtures::tenant('Exported Ltd');

    metric(PlatformMetric::TeamMrr, 't'.$team->getKey().'|'.PlanKey::Team->value, 12_900, $day);

    $operator = Fixtures::operator(AdminRole::Finance);
    $this->actingAs($operator);

    $response = $this->get('/admin/reporting/export?report=margin&range=custom&from='.$day.'&to='.$day);

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertHeader('cache-control', 'no-store, private');

    expect($response->headers->get('content-disposition'))
        ->toContain('alima-platform-margin-'.$day.'-to-'.$day.'.csv');

    $csv = $response->streamedContent();

    expect($csv)->toContain('Exported Ltd')
        // Money in **major units with two decimals** and the currency in the header, so a
        // spreadsheet can sum the column.
        ->and($csv)->toContain('Gross margin (GBP)');

    $entry = AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', AdminAudit::REPORT_EXPORTED)
        ->sole();

    expect($entry->user_id)->toBe($operator->getKey())
        ->and($entry->properties['report'] ?? null)->toBe('margin')
        // The window, and a row count — never the contents.
        ->and($entry->properties['from'] ?? null)->toBe($day)
        ->and($entry->properties)->not->toHaveKey('rows_data');
});

it('refuses an export of a report that does not exist', function (): void {
    $this->actingAs(Fixtures::operator(AdminRole::Finance));

    $this->get('/admin/reporting/export?report=everything')->assertSessionHasErrors('report');
});

/*
 * The margin findings from the Phase 19c review.
 */

it('converts a group once, so a penny of cost per tenant is not rounded to nothing', function (): void {
    // Converting per tenant and then summing floors each row: a hundred tenants costing a fraction
    // of a penny each would every one round to zero, and the group would report **no cost at all**
    // — overstating margin, which is the one direction this report must not err in.
    $day = '2026-10-15';

    for ($i = 0; $i < 100; $i++) {
        [, $team] = Fixtures::tenant('Tiny '.$i);

        metric(PlatformMetric::TeamMrr, 't'.$team->getKey().'|'.PlanKey::Starter->value, 4_900, $day);
        // 5,000 micros at 80p to the dollar is 0.4p — nothing on its own, 40p across a hundred.
        metric(PlatformMetric::TeamCostMicros, 't'.$team->getKey().'|USD', 5_000, $day);
    }

    $perTenant = app(PlatformMarginBuilder::class)->perTeam(oneDay($day));
    $perPlan = app(PlatformMarginBuilder::class)->perPlan(oneDay($day));

    // Each tenant's own row still floors to zero, which is honest at that scale.
    expect($perTenant[0]->cost_minor)->toBe(0)
        ->and($perPlan)->toHaveCount(1)
        // The group does not. 100 × 5,000 micros = 500,000 micros = 40p.
        ->and($perPlan[0]->cost_minor)->toBe(40);
});

it('keeps a revenue-less tenant in the per-plan report rather than dropping it', function (): void {
    // Free and trial tenants never get a `TeamMrr` row, so keying the per-plan report on the plan
    // from that row dropped them — and the Free plan became incapable of ever showing a loss,
    // which is the single figure somebody opens this report for.
    $day = '2026-10-15';
    [, $paying] = Fixtures::tenant('Paying Ltd');
    [, $free] = Fixtures::tenant('Freeloader Ltd');

    metric(PlatformMetric::TeamMrr, 't'.$paying->getKey().'|'.PlanKey::Team->value, 12_900, $day);
    metric(PlatformMetric::TeamCostMicros, 't'.$free->getKey().'|USD', 2_000_000, $day);

    $rows = app(PlatformMarginBuilder::class)->perPlan(oneDay($day));
    $labels = array_column($rows, 'label');

    expect($labels)->toContain('No revenue in this window');

    $noRevenue = $rows[array_search('No revenue in this window', $labels, true)];

    expect($noRevenue->revenue_minor)->toBe(0)
        ->and($noRevenue->cost_minor)->toBe(160)
        ->and($noRevenue->margin_minor)->toBe(-160);
});

it('compares a window against the window before it without overlapping on the boundary day', function (): void {
    // A rolling preset's bounds are mid-day instants, so `DateRange::previous()` reduced to whole
    // days both overlaps the current window on its first date and spans one date more. Every
    // "vs the window before" delta on the screen would be biased, in the direction nobody checks.
    $window = DateRange::for(ReportRange::Custom, 'UTC', '2026-10-11', '2026-10-20');
    $previous = PlatformWindow::previous($window);

    expect($previous)->not->toBeNull()
        ->and($previous->fromDate())->toBe('2026-10-01')
        // Ends the day **before** the current window opens, and spans the same ten dates.
        ->and($previous->toDate())->toBe('2026-10-10');

    metric(PlatformMetric::Signups, 'uk', 7, '2026-10-11');
    metric(PlatformMetric::Signups, 'uk', 3, '2026-10-10');

    $store = app(PlatformMetricStore::class);

    expect($store->total(PlatformMetric::Signups, $window))->toBe(7)
        // The boundary day belongs to exactly one of the two windows.
        ->and($store->total(PlatformMetric::Signups, $previous))->toBe(3);
});

it('has no comparison window for an open-ended one', function (): void {
    expect(PlatformWindow::previous(DateRange::allTime('UTC')))->toBeNull();
});
