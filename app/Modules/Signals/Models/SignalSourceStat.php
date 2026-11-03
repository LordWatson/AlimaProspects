<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Signals\Enums\SignalDismissReason;
use App\Modules\Signals\Services\SignalScorer;
use App\Modules\Signals\Services\SignalTuningAdvisor;
use Database\Factories\SignalSourceStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What this team's own behaviour says about an outlet, for one kind of event (S12).
 *
 * This is the v1 backlog's "learning from dismiss reasons", made concrete and bounded. It feeds
 * two things and nothing else: {@see SignalScorer}'s source-authority component, and the evidence
 * behind a {@see SignalTuningAdvisor} proposal. **Nothing here retunes anything by itself.**
 *
 * **Per `(source, event type)` rather than per source.** An outlet is not good or bad in general —
 * Gulf Business is excellent on funding rounds and says nothing useful about planning permission —
 * and one authority figure per outlet would average those into a number that is wrong about both.
 *
 * `not_an_event_count` is a **subset** of `dismissed_count`, kept separately because it is the
 * only outcome that says the *classifier* was wrong rather than that the team was not interested;
 * {@see SignalDismissReason::indicatesMisclassification()} is what decides, and the advisor reads
 * this column rather than raw dismissals.
 *
 * Tenant-scoped via {@see BelongsToTeam}; nothing routes to it, like {@see SignalRun} and
 * {@see SignalCluster} — a record the module keeps about its own work.
 *
 * @property int $id
 * @property int $team_id
 * @property string $source_key
 * @property string|null $source_name
 * @property int|null $signal_definition_id
 * @property string $event_key
 * @property int $kept_count
 * @property int $dismissed_count
 * @property int $converted_count
 * @property int $not_an_event_count
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SignalSourceStat extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalSourceStatFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'source_key',
        'source_name',
        'signal_definition_id',
        'event_key',
        'kept_count',
        'dismissed_count',
        'converted_count',
        'not_an_event_count',
        'last_seen_at',
    ];

    /**
     * How many of this source's signals the team has been **shown** — the denominator of every rate
     * here, and what `signals.scoring.source_history_minimum` is compared against.
     *
     * **It is `kept_count` alone, and getting that wrong made the advisor dead code.** The first
     * version returned `kept + dismissed`, which reads plausibly and is double counting: a
     * dismissed signal was *also* stored, so it is in both columns. Every rate therefore topped out
     * at 0.5 against a configured threshold of 0.7, so no proposal could ever fire and a source the
     * team dismissed every single time scored an authority of 50 rather than 0. The tests missed it
     * because the fixtures seeded `1 kept, 11 dismissed` — a state production cannot reach (found
     * by `laravel-module-reviewer`).
     *
     * So the four columns are one denominator and three outcomes of it:
     * `kept_count` is what reached the feed, and `dismissed_count`, `converted_count` and
     * `not_an_event_count` are all subsets of it.
     */
    public function observations(): int
    {
        return $this->kept_count;
    }

    /**
     * What this team does with this outlet's stories, as a 0–100 authority figure, or null when
     * there is not enough history to say.
     *
     * Deliberately plain so it can be argued with: of the signals this source produced, the ones
     * the team **did not** dismiss, with a conversion counted **twice** because converting is the
     * only outcome that says the signal was worth money. A source whose every story is dismissed
     * scores 0; one whose stories are routinely converted scores above one whose stories merely sit
     * in the feed unread.
     */
    public function authority(): ?int
    {
        $shown = $this->observations();
        $minimum = max(1, (int) config('signals.scoring.source_history_minimum', 5));

        if ($shown < $minimum) {
            return null;
        }

        $kept = max(0, $shown - $this->dismissed_count);
        $credit = $kept + $this->converted_count;
        $ceiling = $shown + $this->converted_count;

        return $ceiling === 0 ? 0 : (int) round(min(1.0, $credit / $ceiling) * 100);
    }

    /**
     * The share of this source's signals the team dismissed — what a "stop showing me this outlet"
     * proposal is built on.
     *
     * Capped at 1.0: the counters are independent statements and a replayed event could in
     * principle push the dismissals past the keeps, which must not produce a rate above one.
     */
    public function dismissalRate(): float
    {
        $shown = $this->observations();

        return $shown === 0 ? 0.0 : min(1.0, $this->dismissed_count / $shown);
    }

    /**
     * The share of this source's signals the team dismissed **as not an event** — the classifier's
     * own error rate for this outlet, which is what a threshold proposal is built on.
     */
    public function misclassificationRate(): float
    {
        $shown = $this->observations();

        return $shown === 0 ? 0.0 : min(1.0, $this->not_an_event_count / $shown);
    }

    /**
     * The type this row is about, when the definition still exists.
     *
     * `withTrashed`, like every other definition reference in this module: a team deleting a type
     * must not destroy the record of how it performed (ADR 0018 §4).
     *
     * @return BelongsTo<SignalDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(SignalDefinition::class, 'signal_definition_id')->withTrashed();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kept_count' => 'integer',
            'dismissed_count' => 'integer',
            'converted_count' => 'integer',
            'not_an_event_count' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SignalSourceStatFactory
    {
        return SignalSourceStatFactory::new();
    }
}
