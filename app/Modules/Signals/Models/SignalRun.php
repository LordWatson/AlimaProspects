<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Signals\Enums\SignalRunStatus;
use Database\Factories\SignalRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One execution of a {@see SignalWatch} (ADR 0011). Records the query/result/signal counts and the
 * metered cost (Serper + AI) in integer micro-units, mirroring `DiscoveryRun`. Tenant-scoped via
 * {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property int $signal_watch_id
 * @property SignalRunStatus $status
 * @property int $queries_count
 * @property int|null $queries_requested What the plan wanted before the ceiling trimmed it (S8)
 * @property int $results_count
 * @property int $signals_created
 * @property int $cost_micros
 * @property array<array-key, mixed>|null $source_breakdown Written by `SignalSourceBreakdown`; read back defensively, because what is in the column was written by whichever build stored it.
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SignalRun extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalRunFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'signal_watch_id',
        'status',
        'queries_count',
        'paid_queries_count',
        'queries_requested',
        'results_count',
        'signals_created',
        'cost_micros',
        'source_breakdown',
        'error',
        'error_code',
        'started_at',
        'finished_at',
    ];

    /**
     * The watch this run belongs to.
     *
     * @return BelongsTo<SignalWatch, $this>
     */
    public function watch(): BelongsTo
    {
        return $this->belongsTo(SignalWatch::class, 'signal_watch_id');
    }

    /**
     * The code for a run that stopped because the team was out of **AI** credit.
     *
     * A constant here rather than a `SignalsException` code, because this one path does not raise
     * an exception of its own — the run catches `AiCreditExceededException` and settles itself.
     * Named so the run and {@see self::refusalCodes()} cannot disagree about the spelling.
     */
    public const string AI_CREDIT_EXHAUSTED = 'signals.ai_credit_exhausted';

    /**
     * The `error_code`s that mean **this team was refused**, not that a provider failed (S16).
     *
     * One list, on the model that carries the column, because two readers consult it — the nightly
     * `PlatformRollup` and `/admin/signal-runs` — and a provider failure rate that counted a plan
     * limit would have one of them raising alerts about a healthy Serper once an hour per watch,
     * for every team on a ceiling.
     *
     * Deliberately only the **allowance** codes. `signals.source_misconfigured` is a refusal too,
     * and it is ours: a missing API key is exactly what an operator should see on a failure rate.
     *
     * @return list<string>
     */
    public static function refusalCodes(): array
    {
        return [
            // The month's paid searches are gone (S16).
            'signals.source_quota_spent',
            // The team is out of AI credit — the same kind of answer about the same kind of
            // allowance, and it was in the null set until `/code-review` pointed it out.
            self::AI_CREDIT_EXHAUSTED,
        ];
    }

    /**
     * Narrow a query to the failures that are about **a provider** rather than about a team's plan.
     *
     * **A scope rather than a `whereNotIn` at each call site, because `whereNotIn` is wrong here and
     * was wrong here** (found in the S16 security review). `error_code NOT IN ('…')` evaluates to
     * `NULL` — and therefore does not match — for a row whose `error_code` *is* null, which is
     * every failure this column did not exist for, every run settled by
     * `RunSignalWatchJob::failed()` (worker death, retries exhausted — the failures that matter
     * most). So the filter meant to hide one refusal hid almost every real outage, and the platform
     * failure rate would have collapsed towards zero: the exact opposite of what it was added to
     * do, and invisible, because a falling failure rate reads as good news.
     *
     * The **AI-credit** branch used to be in that null set too, and is not any more: it carries
     * {@see self::AI_CREDIT_EXHAUSTED} and is on the list below, because being out of AI credit is
     * the same kind of answer about the same kind of allowance (`/code-review`). What is left in
     * the null set is only what we genuinely want counted: a worker that died, and the failures
     * from before this column existed.
     *
     * `whereNull OR whereNotIn` is the shape SQL's three-valued logic requires, and it lives here so
     * the next reader of this column gets it without rediscovering why.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeProviderFailures(Builder $query): Builder
    {
        return $query
            ->where('status', SignalRunStatus::Failed->value)
            ->where(static fn (Builder $failures): Builder => $failures
                ->whereNull('error_code')
                ->orWhereNotIn('error_code', self::refusalCodes()));
    }

    /**
     * How many searches the ceiling cut from this run, or zero.
     *
     * `queries_requested` is nullable because a run from before S8 never recorded it; null reads as
     * "nobody counted" rather than as a run that wanted nothing, so it reports no trim.
     */
    public function queriesTrimmed(): int
    {
        if ($this->queries_requested === null) {
            return 0;
        }

        return max(0, $this->queries_requested - $this->queries_count);
    }

    /**
     * The signals this run kept.
     *
     * @return HasMany<Signal, $this>
     */
    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => SignalRunStatus::class,
            'queries_count' => 'integer',
            'paid_queries_count' => 'integer',
            'queries_requested' => 'integer',
            'results_count' => 'integer',
            'signals_created' => 'integer',
            'cost_micros' => 'integer',
            'source_breakdown' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SignalRunFactory
    {
        return SignalRunFactory::new();
    }
}
