<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Models\Signal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The Signals side of a data-subject request (S6, ready for Phase 15 / `compliance-privacy.md`).
 *
 * UK GDPR and UAE PDPL both give a named individual the right to ask what a controller holds about
 * them and to have it erased. Signals holds very little — a name, a public role, and a link to a
 * published article — but "very little" is not "nothing", so the module has to be able to answer.
 *
 * **Matching is by name, which is inherently imprecise**, and this class is deliberately honest
 * about that rather than pretending otherwise:
 *
 * - The match is case-insensitive and whole-field-containment, scoped to one team. It is offered
 *   as *candidates* for a human to confirm, never as an automatic erasure — "J. Smith" is not
 *   reliably the same person as "John Smith", and erasing an unrelated company's founder would
 *   itself be a data-integrity failure.
 * - A **converted** signal is reported but **not erased** by {@see self::erase()}. It is the
 *   provenance of a lead someone is actively working, and tearing the origin out from under a lead
 *   would leave the team unable to say where it came from — which is the opposite of the
 *   accountability the request is exercising. Phase 15 decides what happens to the lead, and the
 *   signal goes with that decision.
 *
 * Every method is tenant-scoped. A DSAR is answered per controller, and one team's records are not
 * another's to search.
 */
final class SignalsPersonalDataExporter
{
    /** The shortest name worth matching — a one- or two-letter query would match half the feed. */
    private const int MIN_NAME = 3;

    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * Everything this team's signals hold about a named person, as candidates for review.
     *
     * The returned rows are the **whole** of what Signals holds: there is no email, phone or
     * address to report, because the module never stores one (ADR 0011).
     *
     * @return list<array<string, mixed>>
     */
    public function export(Team $team, string $personName): array
    {
        $name = Str::squish($personName);

        if (Str::length($name) < self::MIN_NAME) {
            return [];
        }

        return $this->teamContext->run($team, function () use ($team, $name): array {
            $rows = [];

            foreach ($this->matches($team, $name) as $signal) {
                $rows[] = [
                    'signal_id' => (int) $signal->getKey(),
                    'status' => $signal->status->value,
                    'event_type' => $signal->event_type->value,
                    'subject_person_name' => $signal->subject_person_name,
                    'subject_person_role' => $signal->subject_person_role,
                    'subject_company_name' => $signal->subject_company_name,
                    'headline' => $signal->headline,
                    'summary' => $signal->summary,
                    'location' => $signal->location,
                    // The public article the record was derived from — the lawful basis for holding
                    // it at all, and the first thing a subject will want to see.
                    'source_url' => $signal->source_url,
                    'source_name' => $signal->source_name,
                    'published_at' => $signal->published_at?->toIso8601String(),
                    'collected_at' => $signal->created_at?->toIso8601String(),
                    // Whether this record can be erased on its own, or is tied to a lead (see the
                    // class docblock).
                    'converted_to_lead_id' => $signal->lead_id,
                    'erasable' => ! $signal->status->isConverted(),
                ];
            }

            return $rows;
        });
    }

    /**
     * Erase the erasable matches and report what was done.
     *
     * Converted signals are **counted and left alone** — the caller gets `retained` and decides,
     * with the lead in hand, what should happen to them. Nothing here is silent.
     *
     * @return array{erased: int, retained: int, retained_signal_ids: list<int>}
     */
    public function erase(Team $team, string $personName): array
    {
        $name = Str::squish($personName);

        if (Str::length($name) < self::MIN_NAME) {
            return ['erased' => 0, 'retained' => 0, 'retained_signal_ids' => []];
        }

        return $this->teamContext->run($team, function () use ($team, $name): array {
            $retained = [];
            $erasable = [];

            foreach ($this->matches($team, $name) as $signal) {
                if ($signal->status->isConverted()) {
                    $retained[] = (int) $signal->getKey();

                    continue;
                }

                $erasable[] = (int) $signal->getKey();
            }

            $erased = $erasable === []
                ? 0
                : (int) Signal::query()->where('team_id', $team->getKey())->whereIn('id', $erasable)->delete();

            // Counts and ids only. Logging the name here would recreate, in the log, exactly the
            // record the subject asked us to erase (CLAUDE.md logging rules).
            Log::channel('signals')->info('Signals DSAR erasure completed.', [
                'team_id' => (int) $team->getKey(),
                'erased' => $erased,
                'retained' => count($retained),
            ]);

            return [
                'erased' => $erased,
                'retained' => count($retained),
                'retained_signal_ids' => $retained,
            ];
        });
    }

    /**
     * This team's signals naming the person. Scoped by `team_id` beside the global scope, and
     * matched with an escaped `LIKE` so a name containing `%` or `_` searches for itself.
     *
     * @return Collection<int, Signal>
     */
    private function matches(Team $team, string $name): Collection
    {
        return Signal::query()
            ->where('team_id', $team->getKey())
            ->whereRaw("LOWER(subject_person_name) LIKE ? ESCAPE '\\'", ['%'.$this->escapeLike(Str::lower($name)).'%'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Neutralise the `LIKE` wildcards in a value that is being searched *for*, so a subject named
     * with an underscore doesn't match every single character in its place.
     *
     * The query declares `ESCAPE '\'` explicitly rather than relying on the driver's default:
     * MySQL happens to use backslash, SQLite has **no** default escape character, and without the
     * clause the tests would be validating behaviour that differs from production.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
