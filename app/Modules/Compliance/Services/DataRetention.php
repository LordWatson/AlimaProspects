<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Enums\RetentionAction;
use App\Modules\Compliance\Models\RetentionPolicy;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The retention rules in one place (docs/compliance.md §3.4): what each team's windows are, which
 * of its leads have fallen outside them, and what should happen to each.
 *
 * It is the single source for both the sweep (`compliance:purge`) and the screens — the settings
 * page quotes the same windows, and the lead detail reads `anonymised_at` from the same column the
 * sweep writes. A retention promise computed in two places is a promise that will eventually
 * disagree with itself.
 *
 * **Two branches, and the split is the policy** (see {@see RetentionAction}):
 *
 * - **Untouched** leads — nobody approached them, wrote a note, took a task, took ownership or
 *   moved them off the default column — are **deleted** after the uncontacted window, measured from
 *   when they were sourced. There is nothing to account for: the team never acted on the record.
 * - **Everything else** is **anonymised** after the contacted window, measured from the last thing
 *   that happened to it. The personal data goes; the company record and the fact, date, channel and
 *   outcome of every approach stay, because that is the team's own evidence of who they contacted
 *   and when. Deleting it would destroy the accountability the retention rule sits beside.
 *
 * The two branches between them cover every lead, which is the point: a rule with a gap keeps
 * somebody's data for ever and nobody notices, because nothing fails.
 *
 * Windows are measured on **`created_at` / activity timestamps**, never on a provider's own dates:
 * the promise is about how long Alima holds the record (the same reasoning as Signals' sweep, §5a).
 */
final class DataRetention
{
    /**
     * The relations that mean a lead has been **worked**, in one list so the delete branch and the
     * anonymise branch cannot drift apart — one is the exact negation of the other, and that is
     * what makes the two sets partition every lead rather than overlapping or leaving a gap.
     *
     * @var list<string>
     */
    private const array WORK_RELATIONS = [
        'contactAttempts',
        'outreachMessages',
        'notes',
        'tasks',
        'assignments',
    ];

    /**
     * This team's windows, whether or not it has saved a policy row.
     *
     * A team with no row is **still swept**, from the configured defaults — storage limitation
     * applies from the first day rather than from the first visit to the settings screen.
     *
     * @return array{enabled: bool, uncontacted_days: int, contacted_days: int}
     */
    public function windowsFor(Team $team): array
    {
        $policy = $this->policyFor($team);

        if ($policy === null) {
            return [
                'enabled' => true,
                'uncontacted_days' => $this->defaultUncontactedDays(),
                'contacted_days' => $this->defaultContactedDays(),
            ];
        }

        return [
            'enabled' => $policy->is_enabled,
            'uncontacted_days' => $policy->uncontacted_lead_days,
            'contacted_days' => $policy->contacted_lead_days,
        ];
    }

    /**
     * The team's stored policy, or null when it has never saved one. Read without the tenant scope
     * because the sweep is a system job running across every team from the console — the `team_id`
     * clause here is the scoping, which is why it is explicit.
     */
    public function policyFor(Team $team): ?RetentionPolicy
    {
        return RetentionPolicy::query()
            ->withoutGlobalScopes()
            ->where('team_id', $team->getKey())
            ->first();
    }

    public function defaultUncontactedDays(): int
    {
        return $this->clamp((int) config('compliance.retention.defaults.uncontacted_lead_days', 180));
    }

    public function defaultContactedDays(): int
    {
        return $this->clamp((int) config('compliance.retention.defaults.contacted_lead_days', 540));
    }

    /**
     * The narrowest window a team may set, and the widest. Exposed so the form and the Action
     * validate against the same numbers the sweep is bounded by.
     */
    public function minDays(): int
    {
        return max(1, (int) config('compliance.retention.min_days', 30));
    }

    public function maxDays(): int
    {
        return max($this->minDays(), (int) config('compliance.retention.max_days', 1825));
    }

    /**
     * Leads this team should **delete**: sourced before the cut-off and never touched by anybody.
     *
     * "Untouched" is deliberately strict. A lead with a note on it, a task, an owner, or one that
     * has been moved off the default board column has been worked, whatever the contact log says —
     * and deleting a company somebody has been nursing through a pipeline because nobody pressed
     * "log a call" would be the sweep destroying live work. Those fall to
     * {@see self::anonymisable()} instead.
     *
     * @return Builder<Lead>
     */
    public function deletable(Team $team, ?Carbon $now = null): Builder
    {
        $windows = $this->windowsFor($team);
        $cutoff = ($now ?? Carbon::now())->copy()->subDays($windows['uncontacted_days']);
        $pin = $this->teamPin($team);

        $query = $this->scoped($team)
            ->where('created_at', '<=', $cutoff)
            ->whereNull('pipeline_stage_id');

        foreach (self::WORK_RELATIONS as $relation) {
            $query->whereDoesntHave($relation, static fn ($related) => $pin($related));
        }

        return $query;
    }

    /**
     * Leads this team should **anonymise**: worked at some point, and quiet ever since.
     *
     * "Quiet" is measured against the newest of the lead's own `updated_at`, its last logged
     * approach, its last timeline entry and its newest drafted message. All four, because each
     * misses something on its own: a lead moved between columns writes an activity but may not
     * touch `updated_at`, a call logged last week may record an `occurred_at` from last month, and
     * a draft writes no timeline entry at all.
     *
     * @return Builder<Lead>
     */
    public function anonymisable(Team $team, ?Carbon $now = null): Builder
    {
        $windows = $this->windowsFor($team);
        $cutoff = ($now ?? Carbon::now())->copy()->subDays($windows['contacted_days']);
        $pin = $this->teamPin($team);

        /** Recently active on `$column`, whatever table the relation points at. */
        $since = static fn (string $column): callable => static function ($related) use ($pin, $column, $cutoff): void {
            $pin($related);
            $related->where($column, '>', $cutoff);
        };

        return $this->scoped($team)
            ->where('updated_at', '<=', $cutoff)
            // Not in the deletable set: a never-touched lead is deleted outright, and a lead that
            // matched both branches would be anonymised one night and deleted the next, which is a
            // promise nobody could explain. This group is the exact negation of the conditions in
            // {@see self::deletable()}, so the two sets partition every lead between them.
            ->where(static function (Builder $query) use ($pin): void {
                $query->whereNotNull('pipeline_stage_id');

                foreach (self::WORK_RELATIONS as $relation) {
                    $query->orWhereHas($relation, static fn ($related) => $pin($related));
                }
            })
            ->whereDoesntHave('contactAttempts', $since('occurred_at'))
            ->whereDoesntHave('activities', $since('occurred_at'))
            // A **drafted message** is the one kind of work that writes no timeline entry: a draft
            // is not an approach, so Phase 11 records it only when it is sent or logged. A draft
            // written last week on an otherwise quiet lead is somebody mid-sentence, and blanking
            // its body would be the sweep editing live work.
            //
            // Notes, tasks and assignments need no clause of their own — each of their Actions
            // records a timeline entry, so the `activities` check above already sees them.
            ->whereDoesntHave('outreachMessages', $since('created_at'));
    }

    /**
     * How many leads each branch would touch right now, for the settings screen and `--dry-run`.
     *
     * Counts rather than a per-lead verdict, deliberately: answering "what will happen to *this*
     * lead" costs half a dozen EXISTS subqueries, and a leads list that asked it per row would pay
     * that twenty-five times to render a sentence nobody reads. A team that wants to know which
     * ones runs the dry run.
     *
     * @return array<string, int> keyed by {@see RetentionAction} value
     */
    public function counts(Team $team, ?Carbon $now = null): array
    {
        if (! $this->windowsFor($team)['enabled']) {
            return [
                RetentionAction::Deleted->value => 0,
                RetentionAction::Anonymised->value => 0,
            ];
        }

        return [
            RetentionAction::Deleted->value => $this->deletable($team, $now)->count(),
            RetentionAction::Anonymised->value => $this->anonymisable($team, $now)->count(),
        ];
    }

    /**
     * How many leads a team may process in one pass. Bounded so a team with a hundred thousand
     * stale leads does not hold a transaction open for the whole sweep.
     */
    public function chunkSize(): int
    {
        return max(1, (int) config('compliance.retention.chunk', 200));
    }

    /**
     * The team's not-yet-anonymised leads, **without the tenant global scope**.
     *
     * The sweep runs from the console with no active team, so the global scope would be skipped
     * anyway; scoping explicitly by `team_id` here is what makes that safe rather than accidental,
     * and it is the same clause whether or not a context happens to be set.
     *
     * @return Builder<Lead>
     */
    private function scoped(Team $team): Builder
    {
        return Lead::query()
            ->withoutGlobalScopes()
            ->where('team_id', $team->getKey())
            ->whereNull('anonymised_at');
    }

    /**
     * The constraint applied inside every existence subquery: drop the related model's tenant
     * global scope and pin the team explicitly.
     *
     * **This is not belt-and-braces, it is the correctness of an irreversible delete.** Each of
     * those relations is a `BelongsToTeam` model, so the subquery would otherwise inherit whatever
     * team happens to be in {@see AppModulesCoreSupportTeamContext}. Sweeping team B while
     * team A's context is active would make B's worked leads look untouched — and the delete branch
     * would hard-delete live work. Nothing does that today (the command and the job set no
     * context), but the next "run now" button would, and "safe because nobody calls it from a
     * request yet" is not a property a destructive query should depend on.
     *
     * The closure takes an **untyped** query and returns nothing on purpose: `whereHas()` is
     * generic over whichever related model it is called for, and a narrower signature would not
     * match the union of the five.
     */
    private function teamPin(Team $team): callable
    {
        $teamId = $team->getKey();

        return static function ($query) use ($teamId): void {
            $query->withoutGlobalScopes()->where($query->getModel()->qualifyColumn('team_id'), $teamId);
        };
    }

    private function clamp(int $days): int
    {
        return max($this->minDays(), min($this->maxDays(), $days));
    }
}
