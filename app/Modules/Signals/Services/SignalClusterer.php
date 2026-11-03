<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\LeadSourcing\Support\CompanyNameKey;
use App\Modules\Signals\Actions\RunSignalWatchAction;
use App\Modules\Signals\Data\SourceItem;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One event, one card (S11): which articles are about the same thing, and the {@see SignalCluster}
 * rows that record it.
 *
 * **Deterministic, and no AI.** Nothing here asks a model anything. That is not thrift — it is what
 * makes the grouping reproducible, testable against fixtures, and safe to run *before* the AI batch,
 * which is where the cost saving is: eight outlets covering one acquisition become one
 * classification instead of eight ({@see RunSignalWatchAction}).
 *
 * Two different questions, answered by two halves of this class, and conflating them was the first
 * draft's mistake:
 *
 * 1. **Before the AI** ({@see self::group()}) nothing is known about the event — not its type, not
 *    its subject, only the headlines. So the grouping there is headline overlap: the significant
 *    words of two titles, compared as sets. It is *provisional* and it only has to be good enough
 *    to pick one representative per story.
 * 2. **After the AI** ({@see self::clusterKeyFor()}) the event has a type and a subject, so the
 *    cluster key is the real one — `(event key, subject fingerprint, published week)` — and it is
 *    what makes a cluster survive across runs, which a headline comparison inside one run never
 *    could. A story covered again tomorrow joins the cluster it already has.
 *
 * **The subject fingerprint reuses the name rule rather than inventing one.**
 * {@see CompanyNameKey::bare()} is the same normalisation `SourcedLead::dedupeKey()` and
 * {@see SignalLeadMatcher} use — a third name normaliser is how two leads become three. It is a
 * **company name and never a person's**, for a compliance reason rather than a technical one: a
 * cluster is not swept by retention (its signals are), so a person's name on it would outlive the
 * rows it came from. A signal naming only a person therefore gets no cluster and stays its own
 * card.
 *
 * **Two deliberate departures from the plan's sketch**, both recorded in `signals-v2-plan.md` and
 * the module doc:
 *
 * - **The amount is not in the fingerprint.** The plan put it there to keep two funding rounds for
 *   one company in one week apart. But an amount is exactly the field outlets disagree about — Gulf
 *   News writes "$40m", Zawya writes nothing — so including it splits the coverage this phase
 *   exists to merge, and it does so in the common case to guard against a rare one. Two rounds for
 *   one company inside a week merge, which is a known limitation with a config flag behind it, and
 *   a funding round and an acquisition are already separated by `event_key`.
 * - **A key is looked up against the previous week as well as its own.** A story that breaks on a
 *   Sunday and is covered on the Monday sits in two ISO weeks, and a week bucket read literally
 *   would make two clusters out of the event most likely to be well covered. So
 *   {@see self::candidateKeysFor()} offers both and {@see self::ensure()} joins an existing
 *   neighbour before creating anything — which also means rows are reconciled in publication order,
 *   oldest first, so the join is the same whichever way a run happens to have found them.
 */
final class SignalClusterer
{
    public function __construct(private readonly SignalItemNormaliser $normaliser) {}

    /**
     * Group candidate articles by what the headlines have in common, **before** anything is
     * classified.
     *
     * Each returned group is a list whose **first element is the representative** — the item with
     * the most text for the classifier to read, ties broken by dedupe identity so the choice does
     * not depend on array order. The caller sends the representative to the AI and applies the
     * verdict to every member.
     *
     * With `signals.cluster_before_ai` off, every item is its own group and the run behaves exactly
     * as it did in v1. That flag is the way back from a regression without a rollback.
     *
     * @param  list<SourceItem>  $items
     * @return list<list<SourceItem>>
     */
    public function group(array $items): array
    {
        if ($items === [] || ! (bool) config('signals.cluster_before_ai', true)) {
            return array_map(static fn (SourceItem $item): array => [$item], $items);
        }

        $threshold = $this->similarityThreshold();
        $minShared = $this->minimumSharedTokens();

        // Two parallel lists rather than one list of pairs: the token set is what a new item is
        // compared against and the members are what comes back, and keeping them apart means
        // appending a member cannot reshape the thing the comparison reads.
        /** @var list<list<string>> $signatures */
        $signatures = [];
        /** @var list<list<SourceItem>> $groups */
        $groups = [];

        foreach ($items as $item) {
            $tokens = $this->normaliser->headlineTokens($item->title, $item->source);
            $joined = null;

            foreach ($signatures as $index => $signature) {
                if ($this->agree($tokens, $signature, $threshold, $minShared)) {
                    $joined = $index;

                    break;
                }
            }

            if ($joined === null) {
                $signatures[] = $tokens;
                $groups[] = [$item];

                continue;
            }

            $groups[$joined][] = $item;
        }

        return array_map(
            fn (array $group): array => $this->representativeFirst($group),
            $groups,
        );
    }

    /**
     * The company this event is about, as a comparable key — or an empty string when the event
     * names no company, which the caller reads as "do not cluster this".
     */
    public function subjectFingerprint(?string $company): string
    {
        return $company === null ? '' : CompanyNameKey::bare($company);
    }

    /**
     * The cluster key for a classified event: `sha1(event key | subject fingerprint | ISO week)`.
     *
     * Null when there is no subject fingerprint. A key built on an empty subject would collect
     * every unattributed event of one type in one week into a single card, which is a worse answer
     * than one card each.
     */
    public function clusterKeyFor(string $eventKey, string $fingerprint, DateTimeInterface $publishedAt): ?string
    {
        if ($fingerprint === '') {
            return null;
        }

        return $this->key($eventKey, $fingerprint, $this->week($publishedAt, 0));
    }

    /**
     * The keys this event may already be filed under: its own week, then the week before.
     *
     * Order matters — the caller takes the first that exists, so an event's own week wins over its
     * neighbour, and only the first is ever created.
     *
     * @return list<string>
     */
    public function candidateKeysFor(string $eventKey, string $fingerprint, DateTimeInterface $publishedAt): array
    {
        if ($fingerprint === '') {
            return [];
        }

        return [
            $this->key($eventKey, $fingerprint, $this->week($publishedAt, 0)),
            $this->key($eventKey, $fingerprint, $this->week($publishedAt, -1)),
        ];
    }

    /**
     * Find or create the cluster one event belongs to, and return it.
     *
     * Team-scoped twice — by the global scope and by an explicit `team_id` — because this is
     * reachable from a queued run with no request behind it, and a cluster that collected another
     * tenant's signals would be the worst bug this phase could have.
     *
     * The caller passes the signal's own definition id so a cluster born from a run carries the
     * live attribution; a cluster found from an earlier week keeps whatever it had, because an
     * event's type does not change because a later article arrived.
     */
    public function ensure(
        int $teamId,
        string $eventKey,
        ?int $definitionId,
        string $fingerprint,
        DateTimeInterface $publishedAt,
    ): ?SignalCluster {
        $candidates = $this->candidateKeysFor($eventKey, $fingerprint, $publishedAt);

        if ($candidates === []) {
            return null;
        }

        $existing = SignalCluster::query()
            ->where('team_id', $teamId)
            ->whereIn('cluster_key', $candidates)
            ->get()
            ->keyBy('cluster_key');

        foreach ($candidates as $candidate) {
            $cluster = $existing->get($candidate);

            if ($cluster instanceof SignalCluster) {
                return $cluster;
            }
        }

        // `firstOrCreate` rather than `create`: two runs of two watches over the same news can
        // reach this line at once, and the unique index is what decides. Losing the race is not an
        // error — it means somebody else wrote the row we were about to.
        return SignalCluster::query()->firstOrCreate(
            [
                'team_id' => $teamId,
                'cluster_key' => $candidates[0],
            ],
            [
                'signal_definition_id' => $definitionId,
                'event_key' => $eventKey,
                'subject_fingerprint' => $fingerprint,
                'sources_count' => 0,
            ],
        );
    }

    /**
     * Recompute a cluster from its members: how many outlets, the window they span, and which
     * article represents it.
     *
     * **A record over the rows, not a counter.** Called whenever membership changes, so the numbers
     * on the card are derived from the signals that exist rather than from a tally that drifted —
     * the same reasoning `usage_meters` has over the AI ledgers (ADR 0012).
     *
     * The primary is the highest-confidence member, earliest publication breaking ties, lowest id
     * after that so the choice is stable. **Unless it has been actioned:** a primary a member has
     * dismissed or converted stays the primary, because it is what carries `lead_id` and the
     * member's decision, and re-pointing a converted cluster at a different article would strand
     * the provenance of a real lead.
     *
     * Returns the cluster, refreshed.
     */
    public function reconcile(SignalCluster $cluster): SignalCluster
    {
        $teamId = (int) $cluster->team_id;

        $members = Signal::query()
            ->where('team_id', $teamId)
            ->where('signal_cluster_id', $cluster->getKey())
            ->orderByDesc('confidence')
            ->orderBy('published_at')
            ->orderBy('id')
            ->get(['id', 'team_id', 'status', 'confidence', 'published_at']);

        $current = $members->firstWhere('id', $cluster->primary_signal_id);

        $primary = $current instanceof Signal && $current->status !== SignalStatus::New
            ? $current
            : $members->first();

        $published = $members
            ->pluck('published_at')
            ->filter()
            ->sort()
            ->values();

        $cluster->forceFill([
            'sources_count' => $members->count(),
            'primary_signal_id' => $primary?->getKey(),
            'first_published_at' => $published->first(),
            'last_published_at' => $published->last(),
        ])->save();

        return $cluster;
    }

    /**
     * Carry a member's status across the rest of its cluster.
     *
     * **A cluster is one event, so it has one status.** The feed lists the primary and counts the
     * primary, which means a member left behind on `New` would be a row nothing shows, nothing
     * counts and retention deletes in 180 days — quietly shortening the coverage on a card somebody
     * is still reading. Dismissing a cluster dismisses its members (the plan's own wording), and
     * converting or restoring follow for the same reason.
     *
     * **`lead_id` is not propagated.** Exactly one article is the provenance of the lead that was
     * created from it, and writing the same `lead_id` onto eight rows would have eight signals each
     * claiming to have made it — which is what `SignalLeadOriginProvider` reads to show a lead where
     * it came from.
     *
     * **A converted member is never overwritten**, and that one is a data-loss bug rather than a
     * tidiness rule (found in review). A cluster can hold a converted signal and a new one — the
     * team converted an article in the morning and another outlet ran the story in the afternoon —
     * and dismissing the new primary would flip the converted row to `Dismissed`. Converted has no
     * retention window and dismissed has ninety days, so that single `update()` would have
     * scheduled the deletion of a working lead's provenance. `SignalStatus::isConverted()` is the
     * same guard `DismissSignalAction` applies to the row a member actually clicked.
     *
     * Called **inside** the caller's transaction, after the actioned row has been saved. Scoped to
     * the signal's own team and to its cluster, and it skips the actioned row itself so an audit
     * trail is never written twice for one decision — the **count** goes in the actioned row's own
     * audit entry instead, so the trail records how far one decision reached.
     *
     * Returns how many members followed.
     */
    public function propagateStatus(Signal $actioned): int
    {
        $clusterId = $actioned->signal_cluster_id;

        if ($clusterId === null) {
            return 0;
        }

        return Signal::query()
            ->where('team_id', (int) $actioned->team_id)
            ->where('signal_cluster_id', $clusterId)
            ->whereKeyNot($actioned->getKey())
            ->where('status', '!=', SignalStatus::Converted->value)
            ->update([
                'status' => $actioned->status->value,
                'dismiss_reason' => $actioned->dismiss_reason?->value,
                'actioned_by' => $actioned->actioned_by,
                'actioned_at' => $actioned->actioned_at,
                'updated_at' => now(),
            ]);
    }

    /**
     * The ISO year and week an event falls in, `$offset` weeks away, in **UTC**.
     *
     * UTC because the bucket must not depend on who is looking: a team in Dubai and a team in
     * London reading the same article have to land on the same cluster, and `DateRange::constrain()`
     * exists in Reporting for the same reason.
     */
    private function week(DateTimeInterface $publishedAt, int $offset): string
    {
        return CarbonImmutable::instance($publishedAt)
            ->utc()
            ->addWeeks($offset)
            ->format('o-\WW');
    }

    private function key(string $eventKey, string $fingerprint, string $week): string
    {
        return sha1($eventKey.'|'.$fingerprint.'|'.$week);
    }

    /**
     * Whether two headlines are reporting the same story.
     *
     * **Dice rather than Jaccard**, and the difference matters in practice: one outlet writes
     * "Regional Holdings acquires Dubai logistics firm Zephyr" and another writes the same thing
     * plus "in an all-cash deal", and Jaccard punishes the longer headline for the words the
     * shorter one simply did not have room for. Dice (`2·shared / (|A| + |B|)`) is symmetric and
     * forgiving about length without being blind to it, which the overlap coefficient — `shared /
     * min(|A|, |B|)` — is: a three-word headline wholly inside a twelve-word one scores a perfect 1
     * under that, which is how a classification ends up attached to the wrong event.
     *
     * The absolute floor sits underneath it, because a ratio is most confident exactly where it has
     * least evidence: two four-word headlines can agree on half their words by accident.
     *
     * **Deliberately strict.** A false merge *here* is not a cosmetic problem — this grouping
     * decides which article the model reads, so two different events in one group means one of them
     * is written down as the other. Under-clustering costs an AI call and a duplicate card; the
     * cards themselves are consolidated by the subject-based cluster key after classification, which
     * cannot mis-merge in that way. So the savings depend on this being generous and the
     * correctness does not.
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $other
     */
    private function agree(array $tokens, array $other, float $threshold, int $minShared): bool
    {
        if (count($tokens) < $minShared || count($other) < $minShared) {
            return false;
        }

        $shared = count(array_intersect($tokens, $other));

        if ($shared < $minShared) {
            return false;
        }

        return ((2 * $shared) / (count($tokens) + count($other))) >= $threshold;
    }

    /**
     * The group, most informative article first.
     *
     * "Most informative" is the longest title plus snippet: the classifier reads text, and the
     * fullest version of a story is the one most likely to name the amount and the counterparty.
     * Ties are broken by dedupe identity so the representative does not depend on the order the
     * provider happened to return.
     *
     * @param  list<SourceItem>  $items
     * @return list<SourceItem>
     */
    private function representativeFirst(array $items): array
    {
        usort($items, static function (SourceItem $a, SourceItem $b): int {
            $weight = (mb_strlen($b->title) + mb_strlen($b->snippet)) <=> (mb_strlen($a->title) + mb_strlen($a->snippet));

            return $weight !== 0 ? $weight : strcmp($a->dedupeKey(), $b->dedupeKey());
        });

        return $items;
    }

    private function similarityThreshold(): float
    {
        $configured = (float) config('signals.clustering.title_similarity', 0.6);

        return min(1.0, max(0.1, $configured));
    }

    private function minimumSharedTokens(): int
    {
        return max(1, (int) config('signals.clustering.min_shared_tokens', 3));
    }
}
