<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Actions\ListSignalsAction;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Enums\SignalStatus;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Spatie\LaravelData\Data;

/**
 * The signals feed's tab + filter state (S5) — the one shape the request, the
 * {@see ListSignalsAction} query and the Inertia props all speak, mirroring `LeadFilters` in the
 * Leads Workspace.
 *
 * Every value is narrowed to an enum, a bounded integer or a parsed date on the way in, so nothing
 * a caller sends reaches the query builder as a column name or an unbounded comparison. Unknown
 * values are **dropped, not rejected**: a bookmarked URL from an older release still opens the
 * feed, minus the filter that no longer exists.
 */
final class SignalFilters extends Data
{
    /**
     * @param  list<SignalEventType>  $eventTypes
     * @param  int|null  $minRelevance  0–100, or null for "don't filter"
     */
    public function __construct(
        public readonly SignalStatus $status = SignalStatus::New,
        public readonly array $eventTypes = [],
        public readonly ?DateTimeImmutable $from = null,
        public readonly ?DateTimeImmutable $to = null,
        public readonly ?int $minRelevance = null,
    ) {}

    /**
     * Build from a loose array — a query string or validated request data.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $from = self::date($input['from'] ?? null);
        $to = self::date($input['to'] ?? null);

        // A range typed backwards is the member's slip, not an error worth a 422: swap it, because
        // "the 9th to the 1st" plainly means the 1st to the 9th and an empty feed explains nothing.
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return new self(
            // The tab. An unrecognised one lands on New rather than 404-ing a stale bookmark.
            status: SignalStatus::tryFrom((string) ($input['status'] ?? '')) ?? SignalStatus::New,
            eventTypes: self::eventTypes($input['event_types'] ?? null),
            from: $from,
            to: $to,
            minRelevance: self::relevance($input['min_relevance'] ?? null),
        );
    }

    /**
     * The shape the Vue filter bar binds to — every key always present, so the form never has to
     * guess whether a filter is absent or merely empty.
     *
     * @return array{status: string, event_types: list<string>, from: string|null, to: string|null, min_relevance: int|null}
     */
    public function toFormState(): array
    {
        return [
            'status' => $this->status->value,
            'event_types' => array_map(static fn (SignalEventType $type): string => $type->value, $this->eventTypes),
            // Date inputs speak `Y-m-d`, not ISO-8601 with a timezone.
            'from' => $this->from?->format('Y-m-d'),
            'to' => $this->to?->format('Y-m-d'),
            'min_relevance' => $this->minRelevance,
        ];
    }

    /**
     * Whether anything beyond the tab is narrowing the list — so an empty feed can say "nothing
     * matches these filters" (with a way to clear them) rather than "no signals yet", which would
     * be a lie the member can't act on.
     */
    public function isNarrowed(): bool
    {
        return $this->eventTypes !== []
            || $this->from !== null
            || $this->to !== null
            || $this->minRelevance !== null;
    }

    /**
     * @return list<SignalEventType>
     */
    private static function eventTypes(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        $types = [];

        foreach ((array) $value as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $type = SignalEventType::tryFrom($candidate);

            if ($type !== null && ! in_array($type, $types, strict: true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * A relevance floor clamped to 0–100. Zero means "no floor" and is normalised to null, so the
     * query doesn't carry a comparison that can never exclude anything.
     */
    private static function relevance(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $relevance = max(0, min(100, (int) $value));

        return $relevance === 0 ? null : $relevance;
    }

    /**
     * A `Y-m-d` (or any parseable) date, as an immutable value. Unparseable input is dropped
     * rather than thrown: a hand-edited URL should open the feed, not an error page.
     */
    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Date::parse($value)->toDateTimeImmutable();
        } catch (\Throwable) {
            return null;
        }
    }
}
