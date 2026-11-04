<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadsWorkspace\Services\LeadQueryBuilder;
use App\Modules\Signals\Enums\WatchlistEntityType;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Models\SignalInboundEvent;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use Illuminate\Database\Eloquent\Builder;
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
 * **Three tables since S16, not one.** The phase went looking for everywhere the feature had grown
 * a name since S6, and found two:
 *
 * - `signal_watchlist_entries` can hold a **person**: S10 lets a team watch "the accounts you
 *   already care about" and `WatchlistEntityType::Person` is one of the two kinds. A named
 *   individual on a watchlist is a standing instruction to search the news for them, which is
 *   squarely the sort of processing a subject would want to know about and have stopped.
 * - `signal_inbound_events` holds the **payload a customer pushed us** (S13b), whole, for thirty
 *   days. Alima did not choose what is in it, which is exactly why it has to be searched: a request
 *   body saying "Jane Okafor has been appointed CFO" is personal data we are processing whether or
 *   not our own classifier ever read it. Matching inside an arbitrary JSON body is cruder than the
 *   other two — see {@see self::matchingInboundEvents()} — and deliberately so.
 *
 * **Erasing a signal also repairs its cluster**, immediately rather than at the next nightly sweep.
 * A cluster is one event shown as one card; taking the article it was being shown as would
 * otherwise leave the feed showing every remaining member as a separate card until the sweep ran,
 * and a DSAR is answered to a deadline rather than overnight.
 *
 * Every method is tenant-scoped. A DSAR is answered per controller, and one team's records are not
 * another's to search.
 */
final class SignalsPersonalDataExporter
{
    /** The shortest name worth matching — a one- or two-letter query would match half the feed. */
    private const int MIN_NAME = 3;

    /**
     * The `LIKE ... ESCAPE` character, and **not a backslash** — corrected in Phase 15, when the
     * Compliance module became this class's first caller.
     *
     * SQLite has no default escape character, so the clause has to be written out; but in **MySQL**
     * the literal `'\'` is an unterminated string, because the backslash escapes the closing quote.
     * A backslash therefore passes every SQLite test and fails every production query. `!` is the
     * character {@see LeadQueryBuilder} settled on for the
     * same reason.
     */
    private const string LIKE_ESCAPE = '!';

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly SignalClusterer $clusterer,
    ) {}

    /**
     * Everything this team's Signals records hold about a named person, as candidates for review.
     *
     * The returned rows are the **whole** of what Signals holds: there is no email, phone or
     * address to report, because the module never stores one (ADR 0011).
     *
     * Every row carries a `record` key naming which table it came from, because the three are not
     * interchangeable to whoever is reviewing them — a signal is something we found, a watchlist
     * entry is a standing instruction we are acting on, and an inbound event is something a
     * customer sent us.
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
                    'record' => 'signal',
                    'signal_id' => (int) $signal->getKey(),
                    'status' => $signal->status->value,
                    // The key, not the label: a DSAR export is a record of what is held, and a
                    // key is stable where a team-authored name is not.
                    'event_type' => $signal->event_key,
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

            foreach ($this->matchingWatchlistEntries($team, $name) as $entry) {
                $rows[] = [
                    'record' => 'watchlist_entry',
                    'watchlist_entry_id' => (int) $entry->getKey(),
                    'watchlist_name' => $entry->watchlist?->name,
                    'subject_person_name' => $entry->name,
                    // The other names this team has told us the same subject is written under —
                    // part of what is held about them, and the reason an alias is searched too.
                    'aliases' => $entry->aliases ?? [],
                    'domain' => $entry->domain,
                    'is_active' => (bool) $entry->is_active,
                    'collected_at' => $entry->created_at?->toIso8601String(),
                    'converted_to_lead_id' => $entry->lead_id,
                    // Always: an entry is an instruction, not provenance, so there is never a lead
                    // whose history would be torn out by removing it.
                    'erasable' => true,
                ];
            }

            foreach ($this->matchingInboundEvents($team, $name) as $event) {
                $rows[] = [
                    'record' => 'inbound_event',
                    'inbound_event_id' => (int) $event->getKey(),
                    'headline' => $event->title,
                    'summary' => $event->summary,
                    'source_url' => $event->link,
                    'source_name' => $event->source_name,
                    'published_at' => $event->occurred_at?->toIso8601String(),
                    'collected_at' => $event->received_at->toIso8601String(),
                    // **The body is reported whole.** It is the customer's own assertion about this
                    // person and the subject is entitled to see what was said, not our summary of
                    // it — and unlike a signal there is no published article to point at instead.
                    'pushed_payload' => $event->payload,
                    'erasable' => true,
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
     * `erased` counts the signals, as it always has, so an existing caller's number does not change
     * meaning; S16's two surfaces are counted beside it.
     *
     * @return array{erased: int, retained: int, retained_signal_ids: list<int>, watchlist_entries_erased: int, inbound_events_erased: int}
     */
    public function erase(Team $team, string $personName): array
    {
        $name = Str::squish($personName);

        if (Str::length($name) < self::MIN_NAME) {
            return [
                'erased' => 0,
                'retained' => 0,
                'retained_signal_ids' => [],
                'watchlist_entries_erased' => 0,
                'inbound_events_erased' => 0,
            ];
        }

        return $this->teamContext->run($team, function () use ($team, $name): array {
            $retained = [];
            $erasable = [];
            $clusterIds = [];

            foreach ($this->matches($team, $name) as $signal) {
                if ($signal->status->isConverted()) {
                    $retained[] = (int) $signal->getKey();

                    continue;
                }

                $erasable[] = (int) $signal->getKey();

                if ($signal->signal_cluster_id !== null) {
                    // Collected **before** the delete, because afterwards there is no row left to
                    // read the cluster id off.
                    $clusterIds[(int) $signal->signal_cluster_id] = true;
                }
            }

            $erased = $erasable === []
                ? 0
                : (int) Signal::query()->where('team_id', $team->getKey())->whereIn('id', $erasable)->delete();

            $this->repairClusters($team, array_keys($clusterIds));

            $entries = $this->eraseWatchlistEntries($team, $name);
            $events = $this->eraseInboundEvents($team, $name);

            // Counts and ids only. Logging the name here would recreate, in the log, exactly the
            // record the subject asked us to erase (CLAUDE.md logging rules).
            Log::channel('signals')->info('Signals DSAR erasure completed.', [
                'team_id' => (int) $team->getKey(),
                'erased' => $erased,
                'retained' => count($retained),
                'watchlist_entries_erased' => $entries,
                'inbound_events_erased' => $events,
            ]);

            return [
                'erased' => $erased,
                'retained' => count($retained),
                'retained_signal_ids' => $retained,
                'watchlist_entries_erased' => $entries,
                'inbound_events_erased' => $events,
            ];
        });
    }

    /**
     * Put right the clusters an erasure has just emptied or beheaded (S16).
     *
     * Retention's nightly sweep does this for the rows it deletes and the reasoning is the same —
     * `Signal::representatives()` is tolerant of a missing primary and shows every member rather
     * than none, so the visible failure is seven cards where there should be one. The difference is
     * the clock: a DSAR is answered to a statutory deadline, and "it will look right tomorrow
     * morning" is not an answer to a regulator or to the person who asked.
     *
     * A cluster with no members left is **deleted**, not reconciled: the row would otherwise keep
     * its `subject_fingerprint` with nothing left to explain it, which is the one part of a cluster
     * that is derived from the rows that are gone.
     *
     * @param  list<int>  $clusterIds
     */
    private function repairClusters(Team $team, array $clusterIds): void
    {
        if ($clusterIds === []) {
            return;
        }

        /** @var Collection<int, SignalCluster> $clusters */
        $clusters = SignalCluster::query()
            ->where('team_id', $team->getKey())
            ->whereIn('id', $clusterIds)
            ->get();

        foreach ($clusters as $cluster) {
            $remaining = Signal::query()
                ->where('team_id', $team->getKey())
                ->where('signal_cluster_id', $cluster->getKey())
                ->count();

            if ($remaining === 0) {
                $cluster->delete();

                continue;
            }

            $this->clusterer->reconcile($cluster);
        }
    }

    /**
     * Delete the watchlist entries naming the person, whatever kind of list they are on (S16).
     *
     * **A synced list is not spared**, and that is the one decision here worth stating: hand-editing
     * a pipeline list is normally refused because its entries are an output and the next sync would
     * put them straight back. An erasure is not an edit — and the next sync *will* put it back,
     * from the team's own `leads` row, which is Compliance's to erase and not this module's. So the
     * honest report is that Signals has removed what Signals holds; the lead behind it is handled
     * by the same request's Leads Workspace pass.
     */
    private function eraseWatchlistEntries(Team $team, string $name): int
    {
        $matches = $this->matchingWatchlistEntries($team, $name);

        $ids = $matches->map(static fn (SignalWatchlistEntry $entry): int => (int) $entry->getKey())->all();

        if ($ids === []) {
            return 0;
        }

        // The lists to recount, read **before** the delete — afterwards there is no row to read the
        // parent id off.
        $listIds = $matches
            ->map(static fn (SignalWatchlistEntry $entry): int => (int) $entry->signal_watchlist_id)
            // `values()` before `all()`: `map()` keeps the source keys, and a key-preserving array
            // is not a `list`.
            ->values()
            ->all();

        $erased = (int) SignalWatchlistEntry::query()
            ->where('team_id', $team->getKey())
            ->whereIn('id', $ids)
            ->delete();

        // **`entries_count` is a cached counter and this is its second write** (`/code-review`).
        // Every other delete path maintains it in the same breath; without this the Accounts screen
        // shows "5 / 100" over four rows for ever on a manual list, which is only recounted on an
        // add or a remove, and `SignalWatchController` keeps offering a list it filters by
        // `entries_count > 0`.
        SignalWatchlist::recountEntries($listIds, (int) $team->getKey());

        return $erased;
    }

    /**
     * Delete the pushed events whose body names the person (S16).
     */
    private function eraseInboundEvents(Team $team, string $name): int
    {
        $ids = $this->matchingInboundEvents($team, $name)
            ->map(static fn (SignalInboundEvent $event): int => (int) $event->getKey())
            ->all();

        if ($ids === []) {
            return 0;
        }

        return (int) SignalInboundEvent::query()
            ->where('team_id', $team->getKey())
            ->whereIn('id', $ids)
            ->delete();
    }

    /**
     * The person-type watchlist entries naming the subject (S16).
     *
     * **Person entries only.** A company entry's `name` is a company, and a sole trader called
     * "J. Okafor Consulting" is a business whose name happens to contain a person's — erasing it on
     * a name match would take a team's watch off a company for a request that was not about it.
     * `WatchlistEntityType` is what the member chose when they added it, which is the best evidence
     * available of which they meant.
     *
     * The aliases are searched as well as the name, because an alias is a name this team told us
     * the same subject goes by — and a subject whose record we hold only under their alias is
     * exactly the case a DSAR exists to surface.
     *
     * @return Collection<int, SignalWatchlistEntry>
     */
    private function matchingWatchlistEntries(Team $team, string $name): Collection
    {
        $pattern = '%'.$this->escapeLike(Str::lower($name)).'%';

        return SignalWatchlistEntry::query()
            ->where('team_id', $team->getKey())
            ->where('entity_type', WatchlistEntityType::Person)
            ->where(fn (Builder $query) => $query
                ->whereRaw($this->likeLower('name'), [$pattern])
                // `aliases` is a JSON column, and both engines store it as text — so a `LIKE` over
                // it is a contains-match on the serialised array. Crude, and right for a DSAR:
                // over-reporting a candidate for a human to confirm is the safe direction, which
                // is what this whole class is built to do.
                ->orWhereRaw($this->likeLower('aliases'), [$pattern]))
            ->with('watchlist')
            ->orderBy('id')
            ->get();
    }

    /**
     * The pushed events whose stored body names the subject (S16).
     *
     * **Cruder than the other two, on purpose and with its limits stated.** The other surfaces have
     * a column that *is* the person's name; a pushed payload is whatever the customer's integration
     * sent, so the only honest search is a contains-match over the whole serialised body. That
     * over-reports — a body mentioning "Smith Holdings" matches a request about Ada Smith — and
     * over-reporting is the safe direction for a class that offers candidates for a human to
     * confirm.
     *
     * For **erasure** it is still the right trade: the row is a 30-day record of an HTTP request,
     * the signal it produced is a separate row with its own retention, and nothing downstream reads
     * it again (`WebhookSignalSource` offers it to one run inside a shorter window). So a
     * false-positive deletion costs an operator the ability to answer "what exactly did they send
     * us?" about one event, and a false negative costs us a failed erasure.
     *
     * The title, summary and source name are matched explicitly as well as the payload. They are
     * copies of fields *inside* it, but a body whose person's name sits only in a nested key would
     * still be caught by the payload clause — and a reader of this method should not have to work
     * out that the three columns are redundant, because the day a column stops being a copy they
     * are not.
     *
     * **Two limits found in the S16 review, both stated rather than papered over.**
     *
     * The first is a **non-ASCII one, and it is the market this product sells into**. Laravel's
     * `array` cast serialises with `json_encode`'s defaults, so on a driver with no native JSON
     * type — SQLite, which is what the suite runs on — an Arabic name is stored as `\uXXXX` escapes
     * and `LOWER(payload) LIKE '%جين%'` matches nothing. MySQL's `json` column normalises the
     * escapes away, so production is correct and the test suite cannot demonstrate it; the
     * env-gated `SignalsMysqlDialectTest` is what does. The three flat columns beside it are plain
     * strings and match on both, which is the practical mitigation: a pushed event that names
     * somebody only in a nested key **and** only in Arabic is the gap.
     *
     * The second is **over-reach in what is exported**. A loose match can pull in a payload that
     * names other people, and {@see self::export()} returns the body whole — so an export answering
     * for Ada Smith may contain a sentence about her colleague. The alternative, redacting to the
     * matching fragment, would mean telling a subject *less* than we hold about them while claiming
     * to have answered, which is the worse failure of the two; the review path exists so a human
     * reads the candidates before anything is sent. Both are recorded in
     * `docs/modules/signals.md` → "Known limitations".
     *
     * @return Collection<int, SignalInboundEvent>
     */
    private function matchingInboundEvents(Team $team, string $name): Collection
    {
        $pattern = '%'.$this->escapeLike(Str::lower($name)).'%';

        return SignalInboundEvent::query()
            ->where('team_id', $team->getKey())
            ->where(fn (Builder $query) => $query
                ->whereRaw($this->likeLower('payload'), [$pattern])
                ->orWhereRaw($this->likeLower('title'), [$pattern])
                ->orWhereRaw($this->likeLower('summary'), [$pattern])
                ->orWhereRaw($this->likeLower('source_name'), [$pattern]))
            ->orderBy('id')
            ->get();
    }

    /**
     * A case-insensitive escaped `LIKE` over one column.
     *
     * One place rather than seven copies of the same string, because the escape character is the
     * thing in this class that has already been wrong once in a way no SQLite test could see — see
     * {@see self::LIKE_ESCAPE}.
     *
     * The column is typed `literal-string` rather than `string`, which is the type system carrying
     * the rule that matters here: every caller passes a column name written in this file, and a
     * value that had come from a request could not reach it. Raw SQL built from a runtime string is
     * how this method would otherwise become an injection point the day somebody made the column
     * configurable.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    private function likeLower(string $column): string
    {
        return 'LOWER('.$column.") LIKE ? ESCAPE '".self::LIKE_ESCAPE."'";
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
            ->whereRaw($this->likeLower('subject_person_name'), ['%'.$this->escapeLike(Str::lower($name)).'%'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Neutralise the `LIKE` wildcards in a value that is being searched *for*, so a subject named
     * with an underscore doesn't match every single character in its place.
     *
     * See {@see self::LIKE_ESCAPE} for why the escape character is not a backslash.
     */
    private function escapeLike(string $value): string
    {
        $escape = self::LIKE_ESCAPE;

        return str_replace(
            [$escape, '%', '_'],
            [$escape.$escape, $escape.'%', $escape.'_'],
            $value,
        );
    }
}
