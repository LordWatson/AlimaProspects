<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Data\PlaybookStepOutcome;
use App\Modules\Signals\Enums\PlaybookMode;
use Database\Factories\SignalPlaybookRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one playbook did to one signal, and **what it refused to do and why** (S14).
 *
 * The refusals are why this is a table rather than an audit entry. The questions people actually
 * ask of an automation are "why didn't it draft anything for Acme?" and "what would this have done
 * last week?", and a log of successes answers neither. An automation with no audit trail is an
 * automation nobody trusts — so a row is written whenever a rule **fires**, in every mode,
 * including the rehearsal that did nothing.
 *
 * Three asymmetries in the foreign keys, each deliberate:
 *
 * - `signal_playbook_id` is `nullOnDelete` **with the name copied**, because deleting a rule must
 *   not erase the record of what it did to somebody's pipeline (ADR 0018 §4's reasoning).
 * - `signal_id` is `cascadeOnDelete`, which is the opposite choice, because the retention sweep
 *   minimising a prospect's event away should take the record of how we handled it with it.
 * - `lead_id` is `nullOnDelete`: a deleted lead is not a reason to lose the log.
 *
 * `mode` is stored as it was **at the time**. A rule rehearsed for a week and then switched on has
 * a log in two halves, and relabelling the first half would destroy the rehearsal somebody made the
 * decision on.
 *
 * Tenant-scoped via {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $signal_playbook_id
 * @property string $playbook_name
 * @property int $signal_id
 * @property int|null $lead_id
 * @property int|null $actor_id
 * @property PlaybookMode $mode
 * @property array<int, mixed> $results the stored step outcomes, narrowed by
 *                                      {@see self::outcomes()} — the column is JSON written by
 *                                      whichever release saved it
 * @property int $applied_count
 * @property int $refused_count
 * @property int $failed_count
 * @property Carbon|null $completed_at when the results were written; null means interrupted
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SignalPlaybookRun extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalPlaybookRunFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'signal_playbook_id',
        'playbook_name',
        'signal_id',
        'lead_id',
        'actor_id',
        'mode',
        'results',
        'applied_count',
        'refused_count',
        'failed_count',
        'completed_at',
    ];

    /**
     * The rule, when it still exists. `playbook_name` is what the screen reads either way.
     *
     * @return BelongsTo<SignalPlaybook, $this>
     */
    public function playbook(): BelongsTo
    {
        return $this->belongsTo(SignalPlaybook::class, 'signal_playbook_id');
    }

    /**
     * @return BelongsTo<Signal, $this>
     */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class, 'signal_id');
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The steps, narrowed back out of the stored JSON.
     *
     * Read through the DTO rather than at the call site for the same reason the playbook's own
     * columns are: this is JSON that may have been written by an older release, and a screen
     * reaching into `results[2]['reason']` would be the second place its shape is known.
     *
     * @return list<PlaybookStepOutcome>
     */
    public function outcomes(): array
    {
        $outcomes = [];

        foreach ($this->results as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $outcome = PlaybookStepOutcome::fromStored($raw);

            if ($outcome instanceof PlaybookStepOutcome) {
                $outcomes[] = $outcome;
            }
        }

        return $outcomes;
    }

    /**
     * Whether the chain finished. False means the worker died between the claim and the write —
     * see the `completed_at` migration for why that cannot be designed away, only made visible.
     */
    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Whether a person should read this row: something was refused, something failed, or the chain
     * never finished.
     */
    public function needsAttention(): bool
    {
        return $this->refused_count > 0 || $this->failed_count > 0 || ! $this->isComplete();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'mode' => PlaybookMode::class,
            'results' => 'array',
            'completed_at' => 'datetime',
            'applied_count' => 'integer',
            'refused_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    protected static function newFactory(): SignalPlaybookRunFactory
    {
        return SignalPlaybookRunFactory::new();
    }
}
