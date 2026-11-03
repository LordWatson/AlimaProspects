<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Enums\Currency;
use App\Modules\Core\Enums\Market;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Enums\SignalDismissReason;
use App\Modules\Signals\Enums\SignalMatchConfidence;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Services\SignalClusterer;
use Database\Factories\SignalFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A single wealth/liquidity event kept from a {@see SignalRun} (ADR 0011): an AI-classified,
 * AI-summarised news item about a person or company. Holds a person's name and public role only —
 * never email or phone (data minimisation). Unique per team on `url_hash`. On conversion (S4) it
 * points one-way at the {@see Lead} it created. Tenant-scoped via {@see BelongsToTeam}.
 *
 * `signal_watch_id`, `signal_run_id` and `signal_definition_id` are all **nullable**: a converted
 * signal is the provenance of a real lead, so deleting the watch that found it — or the definition
 * that classified it — nulls the attribution rather than destroying the record (the S6 migration,
 * `DeleteSignalWatchAction`, and ADR 0018 §4).
 *
 * `event_key` is the definition's key, **copied** rather than only referenced, for that same
 * reason: a signal whose definition is gone must still render, group and report. It is v1's
 * `event_type` column renamed; the enum it used to hold is gone (ADR 0018 §1).
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $signal_watch_id
 * @property int|null $signal_run_id
 * @property int|null $signal_definition_id
 * @property int|null $signal_cluster_id the event this article is coverage of (S11), or null
 * @property string $event_key
 * @property SignalStatus $status
 * @property SignalDismissReason|null $dismiss_reason
 * @property string $headline
 * @property string $summary
 * @property string|null $subject_person_name
 * @property string|null $subject_person_role
 * @property string|null $subject_company_name
 * @property string|null $counterparty_name
 * @property int|null $amount_minor
 * @property Currency|null $currency
 * @property string|null $location
 * @property string|null $jurisdiction ISO-3166-1 alpha-2, resolved at extraction (S8)
 * @property string|null $resolved_market A `Market` value, or null where the code maps to none
 * @property int $confidence
 * @property int $relevance
 * @property int|null $heat 0-100, composite and deterministic (S12); null until scored
 * @property list<array<string, mixed>>|null $score_breakdown every component's value and share
 * @property Carbon|null $article_fetched_at when the full article was read; never the body (S12)
 * @property string|null $suggested_approach
 * @property string $source_url
 * @property string|null $source_name
 * @property Carbon|null $published_at
 * @property string $url_hash
 * @property string|null $content_hash sha1(source name + normalised headline) (S11)
 * @property string $dedupe_hash the identity this row is unique on: content hash behind a
 *                               redirector, URL hash everywhere else (S11)
 * @property array<string, mixed> $raw
 * @property int|null $lead_id
 * @property int|null $matched_lead_id the lead this event is **about** (S10), not converted into
 * @property SignalMatchConfidence|null $match_confidence
 * @property int|null $actioned_by
 * @property Carbon|null $actioned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Signal extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'signal_watch_id',
        'signal_run_id',
        'signal_definition_id',
        'signal_cluster_id',
        'event_key',
        'status',
        'dismiss_reason',
        'headline',
        'summary',
        'subject_person_name',
        'subject_person_role',
        'subject_company_name',
        'counterparty_name',
        'amount_minor',
        'currency',
        'location',
        'jurisdiction',
        'resolved_market',
        'confidence',
        'relevance',
        'heat',
        'score_breakdown',
        'article_fetched_at',
        'suggested_approach',
        'source_url',
        'source_name',
        'published_at',
        'url_hash',
        'content_hash',
        'dedupe_hash',
        'raw',
        'lead_id',
        'matched_lead_id',
        'match_confidence',
        'actioned_by',
        'actioned_at',
    ];

    /**
     * The watch that produced this signal.
     *
     * @return BelongsTo<SignalWatch, $this>
     */
    public function watch(): BelongsTo
    {
        return $this->belongsTo(SignalWatch::class, 'signal_watch_id');
    }

    /**
     * The run that produced this signal.
     *
     * @return BelongsTo<SignalRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SignalRun::class, 'signal_run_id');
    }

    /**
     * The event this article is coverage of (S11), or null when it stands alone.
     *
     * The distinction worth holding on to: a **signal** is one article and a **cluster** is one
     * event. Eight outlets covering one acquisition are eight signals and one cluster; the feed
     * lists the cluster's primary and the card offers the rest as "reported by 8 sources", because
     * each member is still a real article with a real link worth reading.
     *
     * Nullable in both directions. A signal with no identifiable company subject is not clustered
     * at all ({@see SignalClusterer}), and `nullOnDelete` means losing a cluster turns its members
     * back into standalone cards rather than deleting them.
     *
     * @return BelongsTo<SignalCluster, $this>
     */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(SignalCluster::class, 'signal_cluster_id');
    }

    /**
     * Whether this signal is the one its cluster is shown as.
     *
     * True for an unclustered signal too: a card with no cluster represents itself. This is the
     * question the feed asks of every row, which is why it is a method here rather than a condition
     * repeated at three call sites.
     */
    public function isClusterRepresentative(): bool
    {
        if ($this->signal_cluster_id === null) {
            return true;
        }

        $cluster = $this->relationLoaded('cluster') ? $this->cluster : null;

        return ! $cluster instanceof SignalCluster
            || $cluster->primary_signal_id === null
            || (int) $cluster->primary_signal_id === (int) $this->getKey();
    }

    /**
     * The rows that stand for an event rather than for one article's coverage of it (S11).
     *
     * **One definition, and everything that counts signals has to use it** — the feed, its tab
     * counts, the daily digest and the dashboard. Teaching only the feed about clusters is how a
     * dashboard comes to say "8 new signals" over a feed showing one card, which is the exact
     * discrepancy this phase exists to remove (found in review).
     *
     * Written as "**not** hidden behind somebody else" rather than "is the primary", and the
     * difference is a real failure mode. Retention deletes per signal, so it can take a cluster's
     * primary and leave its members — `primary_signal_id` then nulls, and a predicate asking "are
     * you the primary?" would answer no for every surviving member, so the whole story would vanish
     * from the feed and the counts with the rows still in the table. This way an orphaned cluster
     * shows all of its members until the next reconcile tidies it: too many cards is a visible
     * fault somebody reports, where none is a silent one nobody can see.
     *
     * @param  Builder<Signal>  $query
     */
    #[Scope]
    protected function representatives(Builder $query): void
    {
        $query->whereNotExists(static fn (QueryBuilder $cluster) => $cluster
            ->from('signal_clusters')
            ->whereColumn('signal_clusters.id', 'signals.signal_cluster_id')
            ->whereColumn('signal_clusters.team_id', 'signals.team_id')
            ->whereNotNull('signal_clusters.primary_signal_id')
            ->whereColumn('signal_clusters.primary_signal_id', '!=', 'signals.id'));
    }

    /**
     * The definition that classified this signal, when it still exists. Nullable and
     * `nullOnDelete`, so the signal outlives it — {@see self::eventLabel()} is what a screen reads,
     * and it falls back to the stored key.
     *
     * @return BelongsTo<SignalDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        // **`withTrashed`, and this is the whole point of the relation.** `SignalDefinition` soft
        // deletes, and the soft-delete global scope applies to a relation read as much as to a
        // query — so without this, deleting a type makes `$signal->definition` null and every card,
        // report row and lead-origin panel for the signals it found falls back to a humanised key.
        // ADR 0018 §4 promises the opposite: a signal outlives its definition **with** its
        // attribution. (Found in review; the first version was a plain `belongsTo` and the test
        // only checked that the row still existed, not that the signal could still reach it.)
        return $this->belongsTo(SignalDefinition::class, 'signal_definition_id')->withTrashed();
    }

    /**
     * What to call this kind of event on screen.
     *
     * Reads the definition's name where the relation is loaded and the row still exists, and
     * otherwise humanises the stored key. The fallback is the point: a team that deleted a signal
     * type should see "Funding round" on the signals it already found, not a blank or a slug.
     *
     * Call sites must eager-load `definition` (`preventLazyLoading` is on outside production), which
     * is why this is a method rather than an accessor that could quietly N+1 a page of cards.
     */
    public function eventLabel(): string
    {
        $definition = $this->relationLoaded('definition') ? $this->definition : null;

        return $definition instanceof SignalDefinition
            ? $definition->name
            : Str::headline($this->event_key);
    }

    /**
     * The one-line explainer for this kind of event, or null when the definition is gone — a
     * humanised key is a fair label but it is not a description, and inventing one would be worse
     * than leaving the line out.
     */
    public function eventDescription(): ?string
    {
        $definition = $this->relationLoaded('definition') ? $this->definition : null;

        return $definition instanceof SignalDefinition ? $definition->description : null;
    }

    /**
     * The compliance regime this signal's subject falls under, or null where none was resolved.
     *
     * The column is a plain string rather than an enum cast so a value written before a `Market`
     * case was renamed cannot crash a screen — the same reasoning `OutreachRestriction::fromRaw()`
     * uses. Null does **not** mean unrestricted: `ConvertSignalToLeadAction` falls back to the watch
     * and then to the most restrictive of its regions (ADR 0019 section 2).
     */
    public function resolvedMarket(): ?Market
    {
        return is_string($this->resolved_market) ? Market::tryFrom($this->resolved_market) : null;
    }

    /**
     * The lead this signal was converted into (S4), if any. One-way: Lead Sourcing never depends on
     * Signals.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The lead this event is **about**, as opposed to the one it was converted into (S10).
     *
     * The distinction is the whole point of account monitoring: a matched signal belongs on a
     * lead's page the morning it is published, without anybody converting anything and without a
     * second row for a company the team already has.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function matchedLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'matched_lead_id');
    }

    /**
     * The member who dismissed or converted this signal.
     *
     * @return BelongsTo<User, $this>
     */
    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => SignalStatus::class,
            'dismiss_reason' => SignalDismissReason::class,
            'match_confidence' => SignalMatchConfidence::class,
            'amount_minor' => 'integer',
            'currency' => Currency::class,
            'confidence' => 'integer',
            'relevance' => 'integer',
            'heat' => 'integer',
            'score_breakdown' => 'array',
            'article_fetched_at' => 'datetime',
            'published_at' => 'datetime',
            'raw' => 'array',
            'actioned_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SignalFactory
    {
        return SignalFactory::new();
    }
}
