<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Signals\Data\SignalPlaybookConditions;
use App\Modules\Signals\Data\SignalPlaybookStep;
use App\Modules\Signals\Enums\PlaybookMode;
use App\Modules\Signals\Services\SignalPlaybookEvaluator;
use Database\Factories\SignalPlaybookFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A rule a team owns: *when a signal looks like this, do these things* (S14).
 *
 * **A playbook can prepare an approach. It cannot send one** (docs/compliance.md §3.12). That is
 * not a setting on this row and never will be: `OutreachGuard` answers whether a prospect may be
 * approached, and a UAE-jurisdiction signal carries a prior-consent restriction nothing published
 * can satisfy. The drafting step writes a draft for a person to read, and records the guard's
 * refusal where there is one.
 *
 * The two JSON columns are read back through typed objects rather than at call sites —
 * {@see self::conditionSet()} and {@see self::steps()}. The reason is not tidiness: this is a team's
 * own data, validated once on the way in, and a handler that reached into `actions[3]['params']`
 * would be the second place the shape is known and the one that got it wrong.
 *
 * `is_active` and `mode` are separate statements. Paused means *do not evaluate me*; rehearsing
 * means *evaluate me and tell me what you would have done*. Collapsing them into one column would
 * lose the rehearsal, which is the brake that makes somebody brave enough to switch a rule on.
 *
 * Tenant-scoped via {@see BelongsToTeam}. There are no platform presets on this table — unlike
 * {@see SignalDefinition}, a rule reaches into a team's own stages, members and tags, so a
 * cross-tenant row could not mean anything.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $created_by
 * @property string $name
 * @property bool $is_active
 * @property PlaybookMode $mode
 * @property array<string, mixed> $conditions
 * @property array<int, mixed> $actions the stored steps, narrowed by {@see self::steps()} — the
 *                                      column is JSON written by whichever release saved it
 * @property bool $stop_after_first_match
 * @property int $priority
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SignalPlaybook extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalPlaybookFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'created_by',
        'name',
        'is_active',
        'mode',
        'conditions',
        'actions',
        'stop_after_first_match',
        'priority',
    ];

    /**
     * Who built it. Their authority is what the chain acts under — see `SignalPlaybookActor` for
     * what happens when they leave the team.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What it has done, newest first.
     *
     * @return HasMany<SignalPlaybookRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(SignalPlaybookRun::class, 'signal_playbook_id');
    }

    /**
     * The conditions as the object {@see SignalPlaybookEvaluator} asks.
     *
     * Rebuilt on each call rather than memoised: a playbook is read once per job and this is a
     * handful of array reads, and a cached copy on the model would go stale the moment an Action
     * saved new conditions onto the same instance.
     */
    public function conditionSet(): SignalPlaybookConditions
    {
        return SignalPlaybookConditions::fromStored($this->conditions);
    }

    /**
     * The steps, in order, with anything unrecognised dropped.
     *
     * A step whose kind this build does not know is **left out rather than failing the run**, and
     * that is the deliberate choice a rolled-back deploy makes safe: a team that built a rule on a
     * newer release should have the rest of it honoured, not have every signal error. The dropped
     * step is counted by {@see self::hasUnknownSteps()} so the screen can say so.
     *
     * @return list<SignalPlaybookStep>
     */
    public function steps(): array
    {
        $steps = [];

        foreach ($this->actions as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $step = SignalPlaybookStep::fromStored($raw);

            if ($step instanceof SignalPlaybookStep) {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    /**
     * Whether any stored step was dropped by {@see self::steps()} — so the screen can tell somebody
     * their rule is not doing everything it says, rather than leaving them to notice.
     */
    public function hasUnknownSteps(): bool
    {
        return count($this->steps()) !== count(array_filter($this->actions, 'is_array'));
    }

    /**
     * The rules to evaluate, in the order they run.
     *
     * Ordered by `priority` then `id`, which is the index's own order: two rules a member never
     * gave a priority to run oldest-first, which is what somebody who built them in sequence
     * expects. A paused rule is excluded here rather than skipped later, because "do not evaluate
     * me" should cost nothing.
     *
     * @param  Builder<SignalPlaybook>  $query
     */
    #[Scope]
    protected function runnable(Builder $query): void
    {
        $query->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'mode' => PlaybookMode::class,
            'conditions' => 'array',
            'actions' => 'array',
            'stop_after_first_match' => 'boolean',
            'priority' => 'integer',
        ];
    }

    protected static function newFactory(): SignalPlaybookFactory
    {
        return SignalPlaybookFactory::new();
    }
}
