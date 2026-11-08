<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Data;

use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\LeadSourcing\Enums\EvidenceKind;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadsWorkspace\Actions\ListLeadsAction;
use App\Modules\LeadsWorkspace\Enums\LeadSort;
use App\Modules\LeadsWorkspace\Enums\LeadSortDirection;
use App\Modules\LeadsWorkspace\Models\SavedView;
use App\Modules\Scoring\Enums\ScoreGrade;
use BackedEnum;
use Illuminate\Support\Str;
use ReflectionEnum;
use Spatie\LaravelData\Data;

/**
 * The leads list's filter/search/sort state (Phase 10) — the single shape that the request, the
 * {@see ListLeadsAction} query, the Inertia props and a stored {@see SavedView} all speak.
 *
 * Every value is narrowed to an enum or a trimmed, length-capped string on the way in, so nothing
 * a caller sends can reach the query builder as a column name or an unbounded `LIKE`. Unknown keys
 * and unknown enum values are **dropped, not rejected**: a saved view written by an older release
 * still opens, minus the filter that no longer exists.
 */
final class LeadFilters extends Data
{
    /** Longest search term we'll take — beyond this it isn't a search, it's a payload. */
    public const int MAX_SEARCH = 120;

    /**
     * @param  list<ScoreGrade>  $grades
     * @param  list<LeadSource>  $sources
     * @param  list<EnrichmentStatus>  $enrichmentStatuses
     * @param  list<int>  $stageIds
     * @param  list<int>  $tagIds
     * @param  list<int>  $assigneeIds
     * @param  list<string>  $evidenceSources
     * @param  list<EvidenceKind>  $evidenceKinds
     */
    public function __construct(
        public readonly ?string $search = null,
        public readonly array $grades = [],
        public readonly array $sources = [],
        public readonly array $enrichmentStatuses = [],
        public readonly ?string $country = null,
        public readonly array $stageIds = [],
        public readonly array $tagIds = [],
        public readonly array $assigneeIds = [],
        public readonly bool $unassigned = false,
        // --- Why now (PL1) ---
        // Evidence source keys are **strings, not an enum**: a source is data here (ADR 0018 §1),
        // and a filter that only accepted today's enum cases would silently drop a saved view
        // naming a source a later phase added.
        public readonly array $evidenceSources = [],
        public readonly array $evidenceKinds = [],
        // The one filter whose default is *on*: a lead below the team's confidence threshold is
        // hidden until somebody asks for it. Expressed as "include" rather than "hide" so the
        // default state is an absent key and a bare list still has a bare URL.
        public readonly bool $includeLowConfidence = false,
        public readonly LeadSort $sort = LeadSort::Newest,
        public readonly ?LeadSortDirection $direction = null,
    ) {}

    /**
     * Build from a loose array — query string, validated request data, or a saved view's JSON.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $sort = LeadSort::tryFrom((string) ($input['sort'] ?? '')) ?? LeadSort::Newest;

        return new self(
            search: self::string($input['search'] ?? null, self::MAX_SEARCH),
            grades: self::enums($input['grades'] ?? null, ScoreGrade::class),
            sources: self::enums($input['sources'] ?? null, LeadSource::class),
            enrichmentStatuses: self::enums($input['enrichment_statuses'] ?? null, EnrichmentStatus::class),
            // A two-letter ISO code or a country name the team typed; matched loosely by the query.
            country: self::string($input['country'] ?? null, 64),
            stageIds: self::ids($input['stages'] ?? null),
            tagIds: self::ids($input['tags'] ?? null),
            assigneeIds: self::ids($input['assignees'] ?? null),
            // "Nobody is working these" is a filter in its own right, not an empty assignee list.
            unassigned: filter_var($input['unassigned'] ?? false, FILTER_VALIDATE_BOOLEAN),
            evidenceSources: self::strings($input['evidence_sources'] ?? null, 32),
            evidenceKinds: self::enums($input['evidence_kinds'] ?? null, EvidenceKind::class),
            includeLowConfidence: filter_var($input['include_low_confidence'] ?? false, FILTER_VALIDATE_BOOLEAN),
            sort: $sort,
            direction: LeadSortDirection::tryFrom((string) ($input['direction'] ?? '')),
        );
    }

    /**
     * The same filters, but showing the low-confidence leads too (PL1).
     *
     * Written out rather than cloned through `toArray()`: that transforms enums to their backing
     * values on the way out, so a round trip would quietly reset every enum filter — and a count
     * taken over the wrong filters is worse than no count.
     */
    public function revealingLowConfidence(): self
    {
        return new self(
            search: $this->search,
            grades: $this->grades,
            sources: $this->sources,
            enrichmentStatuses: $this->enrichmentStatuses,
            country: $this->country,
            stageIds: $this->stageIds,
            tagIds: $this->tagIds,
            assigneeIds: $this->assigneeIds,
            unassigned: $this->unassigned,
            evidenceSources: $this->evidenceSources,
            evidenceKinds: $this->evidenceKinds,
            includeLowConfidence: true,
            sort: $this->sort,
            direction: $this->direction,
        );
    }

    /**
     * The direction to order by — the request's, else the one that reads best for this sort.
     */
    public function resolvedDirection(): LeadSortDirection
    {
        return $this->direction ?? $this->sort->defaultDirection();
    }

    /**
     * Whether anything is actually narrowing the list (sort alone isn't a filter).
     */
    public function isFiltering(): bool
    {
        return $this->search !== null
            || $this->grades !== []
            || $this->sources !== []
            || $this->enrichmentStatuses !== []
            || $this->country !== null
            || $this->stageIds !== []
            || $this->tagIds !== []
            || $this->assigneeIds !== []
            || $this->unassigned
            || $this->evidenceSources !== []
            || $this->evidenceKinds !== []
            // Revealing the hidden leads **is** narrowing the question the list is answering, even
            // though it widens the result — an export taken with it on is a different export.
            || $this->includeLowConfidence;
    }

    /**
     * The canonical, snake_case form: what gets stored on a saved view, shared with the frontend,
     * and appended to pagination links. Empty values are omitted so a bare list has a bare URL.
     *
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        return array_filter([
            'search' => $this->search,
            'grades' => self::plucked($this->grades),
            'sources' => self::plucked($this->sources),
            'enrichment_statuses' => self::plucked($this->enrichmentStatuses),
            'country' => $this->country,
            'stages' => $this->stageIds,
            'tags' => $this->tagIds,
            'assignees' => $this->assigneeIds,
            'unassigned' => $this->unassigned ? '1' : null,
            'evidence_sources' => $this->evidenceSources,
            'evidence_kinds' => self::plucked($this->evidenceKinds),
            'include_low_confidence' => $this->includeLowConfidence ? '1' : null,
            'sort' => $this->sort === LeadSort::Newest ? null : $this->sort->value,
            'direction' => $this->direction?->value,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * The form the frontend binds its controls to — every key present, so a Vue `v-model` never
     * has to cope with a missing one.
     *
     * @return array<string, mixed>
     */
    public function toFormState(): array
    {
        return [
            'search' => $this->search ?? '',
            'grades' => self::plucked($this->grades),
            'sources' => self::plucked($this->sources),
            'enrichment_statuses' => self::plucked($this->enrichmentStatuses),
            'country' => $this->country ?? '',
            'stages' => $this->stageIds,
            'tags' => $this->tagIds,
            'assignees' => $this->assigneeIds,
            'unassigned' => $this->unassigned,
            'evidence_sources' => $this->evidenceSources,
            'evidence_kinds' => self::plucked($this->evidenceKinds),
            'include_low_confidence' => $this->includeLowConfidence,
            'sort' => $this->sort->value,
            'direction' => $this->resolvedDirection()->value,
        ];
    }

    /**
     * Narrow a list of raw values to a de-duplicated list of positive integer ids, dropping
     * anything that isn't one. Whether an id is actually the team's own is the query's business —
     * it reads through a team-scoped relation, so a foreign id matches nothing.
     *
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        $ids = [];

        foreach ((array) $value as $candidate) {
            if (! is_numeric($candidate)) {
                continue;
            }

            $id = (int) $candidate;

            if ($id > 0 && ! in_array($id, $ids, strict: true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Narrow a list of raw values to a de-duplicated list of trimmed, length-capped strings,
     * dropping anything that isn't one.
     *
     * Used for the filters whose vocabulary is **data** rather than an enum — an evidence source
     * key, which a later phase may add to without redeploying this class. Whether a key exists is
     * the query's business: one that matches nothing narrows the list to nothing, which is the
     * right answer for a stale bookmark.
     *
     * @return list<string>
     */
    private static function strings(mixed $value, int $max): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        $strings = [];

        foreach ((array) $value as $candidate) {
            $string = self::string($candidate, $max);

            if ($string !== null && ! in_array($string, $strings, strict: true)) {
                $strings[] = $string;
            }
        }

        return $strings;
    }

    /**
     * Narrow a scalar to a trimmed string, or null when there's nothing to it.
     */
    private static function string(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : Str::limit($trimmed, $max, '');
    }

    /**
     * Narrow a list of raw values to a de-duplicated list of enum cases, dropping anything that
     * isn't one. Accepts a single value as well as a list, since a query string often sends one.
     *
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return list<TEnum>
     */
    private static function enums(mixed $value, string $enum): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        // A query string gives everything as a string, but a JSON body can give a number — and
        // `tryFrom` is strict about which it takes, so coerce to the enum's own backing type rather
        // than letting a stray `42` become a TypeError.
        $isIntBacked = (string) (new ReflectionEnum($enum))->getBackingType() === 'int';

        $cases = [];

        foreach ((array) $value as $candidate) {
            if (! is_string($candidate) && ! is_int($candidate)) {
                continue;
            }

            if ($isIntBacked && ! is_numeric($candidate)) {
                continue;
            }

            $case = $enum::tryFrom($isIntBacked ? (int) $candidate : (string) $candidate);

            if ($case !== null && ! in_array($case, $cases, strict: true)) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    /**
     * @param  list<BackedEnum>  $cases
     * @return list<string|int>
     */
    private static function plucked(array $cases): array
    {
        return array_map(static fn (BackedEnum $case): string|int => $case->value, $cases);
    }
}
