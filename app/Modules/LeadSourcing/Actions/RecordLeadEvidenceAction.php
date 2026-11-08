<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Data\EvidenceItem;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Models\LeadEvidence;
use App\Modules\Signals\Actions\ConvertSignalToLeadAction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Record one or more reasons a lead is worth contacting, then recompute what the lead's evidence is
 * worth (PL1).
 *
 * The **only** way a `lead_evidence` row is written, because three things have to be decided here
 * rather than by each caller:
 *
 * - **Reliability is config's, at the moment of capture.** A provider does not get to assert how
 *   much its own word is worth, and the figure is stamped on the row so re-weighting a source
 *   tomorrow never restates what the feed already explained today (ADR 0022).
 * - **The same fact arriving twice updates in place.** The identity is `(lead, source, hash of the
 *   source URL — or of the payload where there is no URL)`, so a nightly re-run of the same
 *   discovery does not turn one reason into thirty, and corroboration keeps meaning *independent
 *   agreement* rather than *repetition*.
 * - **Confidence is recomputed in the same breath.** A lead whose evidence changed but whose
 *   confidence did not would be a lead whose list row disagrees with its own detail panel.
 *
 * Safe to call inside a caller's transaction — {@see ConvertSignalToLeadAction}
 * does, so the article that justified a conversion is saved or lost with the lead it justified.
 */
final class RecordLeadEvidenceAction
{
    /** Longest title we'll store; past this it isn't a heading, it's the article. */
    private const int MAX_TITLE = 255;

    private const int MAX_SUMMARY = 1000;

    public function __construct(private readonly RecalculateLeadConfidenceAction $recalculate) {}

    /**
     * Record one item and return the row it wrote or updated.
     */
    public function handle(Lead $lead, EvidenceItem $item, ?Team $team = null): LeadEvidence
    {
        $rows = $this->handleMany($lead, [$item], $team);

        return $rows[0];
    }

    /**
     * Record several items against one lead, recomputing the lead's confidence **once** at the end.
     *
     * The batch form exists because a discovery provider returning four facts about a company
     * should cost one recalculation, not four — and because the intermediate confidences would
     * each have been a figure that was briefly true and never shown to anybody.
     *
     * @param  iterable<EvidenceItem>  $items
     * @return list<LeadEvidence>
     */
    public function handleMany(Lead $lead, iterable $items, ?Team $team = null): array
    {
        $rows = DB::transaction(function () use ($lead, $items): array {
            $written = [];

            foreach ($items as $item) {
                $written[] = $this->write($lead, $item);
            }

            return $written;
        });

        $this->recalculate->handle($lead, $team);

        return $rows;
    }

    /**
     * Insert the item, or update the row that already claims its identity.
     *
     * `updateOrCreate` rather than a lock: two discovery runs finding the same page at the same
     * moment is an ordinary race, and the unique index is what settles it. The retry exists because
     * losing that race raises a constraint violation rather than returning the winner, exactly as
     * it does in `ConvertSignalToLeadAction` and `ImportLeadsAction`.
     */
    private function write(Lead $lead, EvidenceItem $item): LeadEvidence
    {
        $identity = [
            'lead_id' => $lead->getKey(),
            'source' => Str::limit($item->source, 32, ''),
            'dedupe_hash' => $this->identityHash($item),
        ];

        $attributes = [
            'team_id' => $lead->team_id,
            'kind' => $item->kind,
            'source_label' => $item->sourceLabel,
            'signal_id' => $item->signalId,
            'title' => Str::limit(trim($item->title), self::MAX_TITLE, ''),
            'summary' => $item->summary === null ? null : Str::limit(trim($item->summary), self::MAX_SUMMARY, ''),
            'source_url' => $item->sourceUrl,
            'observed_at' => $item->observedAt,
            // Always now. The caller cannot backdate when we found something out — that is the
            // half of the pair that makes a stale crawl visible.
            'captured_at' => Carbon::now(),
            'reliability' => $this->reliabilityOf($item->source),
            'payload' => $item->payload === [] ? null : $item->payload,
        ];

        try {
            return LeadEvidence::query()->updateOrCreate($identity, $attributes);
        } catch (UniqueConstraintViolationException) {
            return LeadEvidence::query()->updateOrCreate($identity, $attributes);
        }
    }

    /**
     * The identity of the fact: its source URL where it has one, else a digest of what the producer
     * said about it.
     *
     * Falling back to the payload rather than to the title matters for a source that has no URL at
     * all (an import, a hand-added company, a pushed event): keyed on the title, two genuinely
     * different facts with the same heading would collapse into one.
     */
    private function identityHash(EvidenceItem $item): string
    {
        if ($item->sourceUrl !== null && trim($item->sourceUrl) !== '') {
            return sha1(Str::lower(trim($item->sourceUrl)));
        }

        return sha1(implode('|', [
            $item->kind->value,
            Str::lower(trim($item->title)),
            // `format`, not `toDateString`: the parameter is a `DateTimeInterface` (a model
            // datetime arrives as CarbonImmutable, a sibling of Illuminate's Carbon rather than a
            // subclass), and only the interface's own methods are safe to call on it.
            $item->observedAt?->format('Y-m-d') ?? '',
            // `json_encode` of an empty payload is a stable `[]`, so an item with neither URL nor
            // payload is still identified by its kind, title and date — which is the most a
            // producer that told us nothing else can be held to.
            json_encode($item->payload) ?: '',
        ]));
    }

    /**
     * The source's configured weight, or the default for a source nobody has taken a view on.
     *
     * Clamped to 0–100: `config/evidence.php` is editable by environment, and a reliability of 400
     * would silently become a confidence of 100 for every lead that source ever touched.
     */
    private function reliabilityOf(string $source): int
    {
        /** @var array<string, int> $weights */
        $weights = (array) config('evidence.reliability', []);

        $weight = (int) ($weights[$source] ?? $weights['default'] ?? 40);

        return max(0, min(100, $weight));
    }
}
