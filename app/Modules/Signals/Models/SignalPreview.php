<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use Database\Factories\SignalPreviewFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One press of "show me what this finds" (S9): what it cost, and nothing about what it found.
 *
 * **An accounting record, not a cache.** A preview searches the news provider and runs an AI pass,
 * so it spends money without a watch, a run or a signal behind it — and a cost with no row is a
 * cost nobody can cap. This row is what makes the per-team daily preview budget enforceable, and
 * what puts preview spend beside run spend in cost reporting instead of leaving it to be inferred
 * from the AI usage log.
 *
 * It deliberately keeps **no results**. What a preview found is shown once and discarded: a
 * definition nobody has saved has no business putting rows in a team's feed, and the member's own
 * words survive only as `draft_hash` — enough to answer "did they preview the same draft four times
 * in a row", which is the only question anybody asks of this table.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $created_by
 * @property string $draft_hash
 * @property int $queries_count
 * @property int $results_count
 * @property int $kept_count
 * @property int $cost_micros
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SignalPreview extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalPreviewFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'created_by',
        'draft_hash',
        'queries_count',
        'results_count',
        'kept_count',
        'cost_micros',
    ];

    /**
     * The member who pressed it. Nullable: they can leave, and the spend still happened.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Previews since a moment, for the budget check.
     *
     * Scoped by `created_at` rather than by a day column, because the budget window is "today in
     * the team's own timezone" and that is the caller's question to ask — a stored day would have
     * frozen one answer to it.
     *
     * Typed `DateTimeInterface` rather than `Carbon`: `now()` here is a `CarbonImmutable`, which is
     * a *sibling* of `Illuminate\Support\Carbon` rather than a subclass, so the narrower type is a
     * runtime `TypeError` that Larastan does catch and a reader would not expect
     * (`memory/carbon-immutable-param-types.md`).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSince(Builder $query, DateTimeInterface $moment): Builder
    {
        return $query->where('created_at', '>=', $moment);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'queries_count' => 'integer',
            'results_count' => 'integer',
            'kept_count' => 'integer',
            'cost_micros' => 'integer',
        ];
    }

    protected static function newFactory(): SignalPreviewFactory
    {
        return SignalPreviewFactory::new();
    }
}
