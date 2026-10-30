<?php

declare(strict_types=1);

use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadsWorkspace\Data\LeadFilters;
use App\Modules\LeadsWorkspace\Enums\LeadSort;
use App\Modules\LeadsWorkspace\Enums\LeadSortDirection;
use App\Modules\Scoring\Enums\ScoreGrade;

it('narrows raw input to enum cases', function (): void {
    $filters = LeadFilters::fromArray([
        'grades' => ['A', 'C'],
        'sources' => ['web_search'],
        'enrichment_statuses' => ['enriched'],
        'sort' => 'fit',
        'direction' => 'asc',
    ]);

    expect($filters->grades)->toBe([ScoreGrade::A, ScoreGrade::C])
        ->and($filters->sources)->toBe([LeadSource::WebSearch])
        ->and($filters->enrichmentStatuses)->toBe([EnrichmentStatus::Enriched])
        ->and($filters->sort)->toBe(LeadSort::Fit)
        ->and($filters->direction)->toBe(LeadSortDirection::Asc);
});

it('drops a value that is not one of the enum’s rather than failing', function (): void {
    // A saved view written before a grade was renamed must still open, minus that grade.
    $filters = LeadFilters::fromArray(['grades' => ['A', 'Z', 42], 'sort' => 'rowid']);

    expect($filters->grades)->toBe([ScoreGrade::A])
        ->and($filters->sort)->toBe(LeadSort::Newest);
});

it('de-duplicates repeated values', function (): void {
    expect(LeadFilters::fromArray(['grades' => ['A', 'A', 'B']])->grades)
        ->toBe([ScoreGrade::A, ScoreGrade::B]);
});

it('accepts a single value where a list is expected', function (): void {
    // A query string often sends `?sources=web_search` rather than `sources[]=`.
    expect(LeadFilters::fromArray(['sources' => 'web_search'])->sources)
        ->toBe([LeadSource::WebSearch]);
});

it('treats a blank search as no search', function (): void {
    expect(LeadFilters::fromArray(['search' => '   '])->search)->toBeNull()
        ->and(LeadFilters::fromArray(['search' => ''])->search)->toBeNull()
        ->and(LeadFilters::fromArray([])->search)->toBeNull();
});

it('trims a search term and caps its length', function (): void {
    $filters = LeadFilters::fromArray(['search' => '  Bright Bean  ']);

    expect($filters->search)->toBe('Bright Bean');

    $long = LeadFilters::fromArray(['search' => str_repeat('x', LeadFilters::MAX_SEARCH + 50)]);

    expect(mb_strlen((string) $long->search))->toBe(LeadFilters::MAX_SEARCH);
});

it('ignores a value of the wrong shape', function (): void {
    $filters = LeadFilters::fromArray([
        'search' => ['not', 'a', 'string'],
        'country' => ['nope'],
        'grades' => 'A',
    ]);

    expect($filters->search)->toBeNull()
        ->and($filters->country)->toBeNull()
        ->and($filters->grades)->toBe([ScoreGrade::A]);
});

it('falls back to the direction that reads best for the sort', function (): void {
    expect(LeadFilters::fromArray(['sort' => 'company'])->resolvedDirection())
        ->toBe(LeadSortDirection::Asc)
        ->and(LeadFilters::fromArray(['sort' => 'fit'])->resolvedDirection())
        ->toBe(LeadSortDirection::Desc)
        ->and(LeadFilters::fromArray([])->resolvedDirection())
        ->toBe(LeadSortDirection::Desc);
});

it('knows whether anything is narrowing the list', function (): void {
    expect(LeadFilters::fromArray([])->isFiltering())->toBeFalse()
        // Sorting alone is not filtering.
        ->and(LeadFilters::fromArray(['sort' => 'fit'])->isFiltering())->toBeFalse()
        ->and(LeadFilters::fromArray(['grades' => ['A']])->isFiltering())->toBeTrue()
        ->and(LeadFilters::fromArray(['search' => 'acme'])->isFiltering())->toBeTrue()
        ->and(LeadFilters::fromArray(['country' => 'GB'])->isFiltering())->toBeTrue();
});

it('round-trips through its canonical form', function (): void {
    $params = ['search' => 'acme', 'grades' => ['A'], 'country' => 'GB', 'sort' => 'fit'];

    expect(LeadFilters::fromArray(LeadFilters::fromArray($params)->toParams())->toParams())
        ->toBe($params);
});

it('omits empty values — and the default sort — from the canonical form', function (): void {
    expect(LeadFilters::fromArray([])->toParams())->toBe([])
        ->and(LeadFilters::fromArray(['sort' => 'newest'])->toParams())->toBe([])
        ->and(LeadFilters::fromArray(['grades' => []])->toParams())->toBe([]);
});

it('gives the form every key, so the UI never binds to a missing one', function (): void {
    expect(LeadFilters::fromArray([])->toFormState())->toBe([
        'search' => '',
        'grades' => [],
        'sources' => [],
        'enrichment_statuses' => [],
        'country' => '',
        'stages' => [],
        'tags' => [],
        'assignees' => [],
        'unassigned' => false,
        'sort' => 'newest',
        'direction' => 'desc',
    ]);
});

it('narrows id filters, dropping anything that is not one', function (): void {
    $filters = LeadFilters::fromArray([
        'stages' => ['3', 3, 'abc', 0, -1, 7],
        'tags' => [12],
        'assignees' => 'not-an-id',
    ]);

    expect($filters->stageIds)->toBe([3, 7])
        ->and($filters->tagIds)->toBe([12])
        ->and($filters->assigneeIds)->toBe([]);
});

it('reads "unassigned" as its own filter', function (): void {
    expect(LeadFilters::fromArray(['unassigned' => '1'])->unassigned)->toBeTrue()
        ->and(LeadFilters::fromArray(['unassigned' => 'true'])->unassigned)->toBeTrue()
        ->and(LeadFilters::fromArray(['unassigned' => '0'])->unassigned)->toBeFalse()
        ->and(LeadFilters::fromArray([])->unassigned)->toBeFalse()
        // Nobody working a lead is something to filter for, so it narrows the list on its own.
        ->and(LeadFilters::fromArray(['unassigned' => '1'])->isFiltering())->toBeTrue();
});

it('keeps nothing derived from the team’s board in the filter set', function (): void {
    // Whether the default stage is among the ones filtered for is the **query's** business, not
    // the filter set's: `LeadQueryBuilder` resolves it from the team. Storing it here would store
    // something that goes stale the moment the team makes another stage the starting one — and
    // would let one caller (the web list) know it while the API and the export didn't.
    $params = LeadFilters::fromArray(['stages' => [4]])->toParams();

    expect($params)->toBe(['stages' => [4]]);
});
