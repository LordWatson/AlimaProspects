<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\LeadSourcing\Enums\EvidenceKind;
use App\Modules\LeadSourcing\Services\LeadConfidence;
use App\Modules\Signals\Models\Signal;
use Database\Factories\LeadEvidenceFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One dated, sourced reason a lead is worth contacting (PL1). A lead's evidence is its history, and
 * {@see LeadConfidence} is a record over the set of them.
 *
 * Two things about a row here that are easy to get wrong:
 *
 * - **`observed_at` is when the event happened; `captured_at` is when we read it.** Decay is
 *   measured from the first. A funding round announced in March is March's news however long it
 *   took us to notice, and measuring from the second would make a slow crawl look like fresh news.
 * - **`reliability` is stored, not looked up.** It is the source's weight at the moment of capture,
 *   so re-weighting a source tomorrow does not silently restate what the feed already explained
 *   today (ADR 0022's rule for `signals.score_breakdown`, one level out).
 *
 * `payload` is never rendered. It is the provider's own output, kept for audit and re-mapping, and
 * on a signal-derived row it can name a person.
 *
 * @property int $id
 * @property int $team_id
 * @property int $lead_id
 * @property EvidenceKind $kind
 * @property string $source the producing source's storage key; **not** an enum (ADR 0018 §1)
 * @property string|null $source_label
 * @property int|null $signal_id
 * @property string $title
 * @property string|null $summary
 * @property string|null $source_url
 * @property Carbon|null $observed_at when the event happened
 * @property Carbon $captured_at when we found it
 * @property int $reliability the source's weight at capture, 0-100
 * @property array<string, mixed>|null $payload
 * @property bool $is_disputed set once any member flags it wrong; contributes nothing thereafter
 * @property string $dedupe_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LeadEvidence extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<LeadEvidenceFactory> */
    use HasFactory;

    protected $table = 'lead_evidence';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'lead_id',
        'kind',
        'source',
        'source_label',
        'signal_id',
        'title',
        'summary',
        'source_url',
        'observed_at',
        'captured_at',
        'reliability',
        'payload',
        'dedupe_hash',
    ];

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The signal this came from, when it came from one. Null both for evidence that never had a
     * signal and for evidence whose signal the retention sweep has since taken — losing the
     * article must not lose the team's record that the event happened.
     *
     * @return BelongsTo<Signal, $this>
     */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    /**
     * Every member's verdict on this item.
     *
     * @return HasMany<LeadEvidenceFeedback, $this>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(LeadEvidenceFeedback::class);
    }

    /**
     * The timeline order the detail panel reads in: what happened most recently first, falling
     * back to when we found it for an item whose date nobody stated. Stated explicitly because
     * MySQL and SQLite disagree about where a null sorts (the lesson `SignalSortOrder` learned).
     *
     * @param  Builder<LeadEvidence>  $query
     * @return Builder<LeadEvidence>
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query
            ->orderByRaw('coalesce(lead_evidence.observed_at, lead_evidence.captured_at) desc')
            ->orderByDesc('lead_evidence.id');
    }

    /**
     * What the evidence is worth today: its stored reliability, thinned by its age, or **0** if a
     * member has disputed it.
     *
     * Lives on the model rather than in the calculator so the detail panel can show one item's
     * current worth without running the whole lead's arithmetic — the panel explains the score, so
     * it has to be able to speak the same number.
     */
    public function currentWeight(?DateTimeInterface $now = null): float
    {
        if ($this->is_disputed) {
            return 0.0;
        }

        return $this->reliability * $this->decay($now);
    }

    /**
     * The proportion of its original weight this item still carries, `0.5 ^ (age / half_life)`,
     * floored at `evidence.min_decay`.
     *
     * The floor matters: zero would make a lead's own history disappear from its confidence, and a
     * register record from 2015 is still why we believe the company exists. An item dated in the
     * future (a provider's clock, or a planning decision with a future effective date) is treated
     * as today's rather than as worth more than today's.
     */
    public function decay(?DateTimeInterface $now = null): float
    {
        $now ??= Carbon::now();
        $observed = $this->observed_at ?? $this->captured_at;

        $ageDays = max(0.0, $observed->diffInDays($now, absolute: false));
        $decay = 0.5 ** ($ageDays / $this->kind->halfLifeDays());

        return max((float) config('evidence.min_decay', 0.1), $decay);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kind' => EvidenceKind::class,
            'observed_at' => 'datetime',
            'captured_at' => 'datetime',
            'reliability' => 'integer',
            'payload' => 'array',
            // Not fillable: only `SubmitEvidenceFeedbackAction` may dispute a fact, and it does so
            // with `forceFill` inside the transaction that records the verdict. A provider writing
            // its own evidence must not be able to pre-mark it disputed.
            'is_disputed' => 'boolean',
        ];
    }

    protected static function newFactory(): LeadEvidenceFactory
    {
        return LeadEvidenceFactory::new();
    }
}
