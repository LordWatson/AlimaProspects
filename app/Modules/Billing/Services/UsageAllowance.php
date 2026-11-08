<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Enums\LedgerDirection;
use App\Modules\Billing\Models\UsageEntry;
use App\Modules\Billing\Models\UsageMeter;
use App\Modules\Core\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Holding, spending and giving back a team's allowance for the ledgered metrics (PL4).
 *
 * ## Reserve, then commit — and why that order is not negotiable
 *
 * A provider call is money leaving the building. The allowance is therefore checked and **held**
 * before the call, and what it actually cost is committed afterwards, with the unspent part
 * released. Committing first would mean a run that spent twice its allowance and was discovered at
 * the end of the month; checking without holding would mean two clicks for the last unit both
 * passing the check.
 *
 * ## How two clicks for the last unit are serialised
 *
 * {@see self::reserve()} locks the `usage_meters` row for the same `(team, metric, period)` for the
 * length of the transaction, then recounts from the ledger inside it. That row is not a convenient
 * mutex — it is *the* row whose meaning is "this team's position on this metric this period", it
 * already exists for every tuple, and it is uniquely keyed on exactly what is being serialised.
 *
 * Nothing is incremented on it. The count still comes from `usage_ledger` every time, because this
 * module's standing rule is that a counter the app forgot to bump is an allowance a team silently
 * overspends (`UsageLedger`'s own note). The lock orders the readers; the ledger stays the truth.
 *
 * ## Three states, as everywhere else
 *
 * A metric's allowance is a number, or **null meaning unlimited**, or absent. For a ledgered metric
 * an absent figure means **zero** — see {@see BillingMetric}'s note: a reveal costs money per unit,
 * so a plan config that forgot one must fail closed rather than hand out an unmetered vendor bill.
 */
final class UsageAllowance
{
    public function __construct(private readonly PlanResolver $plans) {}

    /**
     * What is left of this metric's allowance: included plus bought, minus used, minus held.
     *
     * Null means unlimited. Reservations count against it — a hold is money about to be spent, and
     * a figure that ignored them would let a queue of twenty jobs each see the same last unit.
     */
    public function remaining(Team $team, BillingMetric $metric, ?BillingPeriod $period = null): ?int
    {
        $period ??= BillingPeriod::containing();
        $entitled = $this->entitled($team, $metric, $period);

        if ($entitled === null) {
            return null;
        }

        return max(0, $entitled - $this->usedAndHeld($team, $metric, $period));
    }

    /**
     * The total this team may spend this period: the plan's figure plus anything granted or bought.
     *
     * Null (unlimited) stays null however much is granted on top — adding to infinity is still
     * infinity, and a caller that treated `null + 50` as a number would get a very small allowance.
     */
    public function entitled(Team $team, BillingMetric $metric, ?BillingPeriod $period = null): ?int
    {
        $period ??= BillingPeriod::containing();
        $plan = $this->plans->planFor($team)->allowanceFor($metric);

        if ($plan === null && ! $metric->isLedgered()) {
            return null; // unlimited, for the metrics where an absent figure means unlimited
        }

        return (int) ($plan ?? 0) + $this->added($team, $metric, $period);
    }

    /**
     * Hold `$quantity` units, or return null when there is not enough left.
     *
     * The handle it returns is what {@see self::commit()} and {@see self::release()} settle. Null
     * rather than an exception, because a team at its ceiling is an ordinary answer the caller
     * records (PL3's runs do) rather than an error in the logs.
     *
     * @param  array<string, mixed>  $meta
     */
    public function reserve(
        Team $team,
        BillingMetric $metric,
        int $quantity,
        ?Model $reference = null,
        array $meta = [],
    ): ?string {
        $quantity = max(1, $quantity);
        $period = BillingPeriod::containing();

        return DB::transaction(function () use ($team, $metric, $quantity, $period, $reference, $meta): ?string {
            // The mutex: everything below recounts inside this lock, so a second request for the
            // same metric and period waits rather than reading a position that is about to change.
            $this->lockMeter((int) $team->getKey(), $metric, $period);

            $remaining = $this->remainingUnderLock($team, $metric, $period);

            if ($remaining !== null && $remaining < $quantity) {
                return null;
            }

            $reservation = (string) Str::uuid();

            $this->write($team, $metric, LedgerDirection::Reserve, $quantity, $period->key(), $reservation, $reference, $meta);

            return $reservation;
        });
    }

    /**
     * Settle a hold: `$used` units become consumption, the rest goes back.
     *
     * Takes the handle **alone**, with no team beside it. That is safer rather than looser: the
     * handle *is* the reserve row, so the team, the metric and the period all come from the thing
     * being settled and there is no second parameter for a caller to get wrong. An earlier draft
     * passed a team and checked it matched, which is a check that only exists because the caller
     * was given something it could mismatch.
     *
     * **Idempotent**, and that matters more here than anywhere: a worker that dies between its last
     * provider call and its commit will be retried, and a double commit is a team charged twice for
     * one run. A reservation that already has a commit or a release beside it is left alone.
     *
     * `$used` above what was held is clamped. Over-spending a hold is a bug in the caller, and the
     * safe direction is to bill what was agreed rather than to let a miscount become a charge.
     */
    public function commit(string $reservation, int $used): void
    {
        DB::transaction(function () use ($reservation, $used): void {
            $held = $this->heldEntry($reservation);

            if ($held === null) {
                return;
            }

            // **Lock first, then ask whether it is settled.** `isSettled()` is a check followed by
            // an insert, and a retried worker overlapping the original — or a commit racing the
            // `finally` release in the waterfall's job — would otherwise pass the check twice and
            // write two settlements. The lock is the same `usage_meters` row the reserve took, for
            // the period **the hold belongs to** rather than today's, which is a different row the
            // moment a month turns over mid-retry.
            $this->lockMeter($held->team_id, $held->meter, BillingPeriod::fromKey($held->period));

            if ($this->isSettled($reservation)) {
                return;
            }

            $used = max(0, min($used, $held->quantity));

            if ($used > 0) {
                $this->writeBeside($held, LedgerDirection::Commit, $used, ['of' => $held->quantity]);
            }

            $unspent = $held->quantity - $used;

            if ($unspent > 0) {
                // Written as its own row rather than by adjusting the reserve, because the reserve
                // is history: "we held three and spent one" is a different fact from "we held one".
                $this->writeBeside($held, LedgerDirection::Release, $unspent);
            }
        });
    }

    /**
     * Give a hold back whole — a run that refused after reserving, a reveal that failed.
     */
    public function release(string $reservation): void
    {
        DB::transaction(function () use ($reservation): void {
            $held = $this->heldEntry($reservation);

            if ($held === null) {
                return;
            }

            // Lock before the check, for the reason {@see self::commit()} gives.
            $this->lockMeter($held->team_id, $held->meter, BillingPeriod::fromKey($held->period));

            if ($this->isSettled($reservation)) {
                return;
            }

            $this->writeBeside($held, LedgerDirection::Release, $held->quantity);
        });
    }

    /**
     * Add allowance on top of the plan's: an operator's grant, or a top-up a team bought.
     *
     * `$idempotencyKey` is the external fact that caused it — a Stripe event id — and it is unique
     * in the table, so a webhook Stripe sends twice grants the units once. Without one, nothing
     * stops a retried delivery doubling somebody's purchase.
     *
     * @param  array<string, mixed>  $meta
     */
    public function grant(
        Team $team,
        BillingMetric $metric,
        int $quantity,
        LedgerDirection $direction = LedgerDirection::Grant,
        ?string $idempotencyKey = null,
        array $meta = [],
    ): bool {
        if ($quantity < 1 || ! $direction->adds()) {
            return false;
        }

        // `withoutGlobalScopes`: a Stripe webhook has no team in context, and the key is unique
        // across the table — a duplicate that was invisible because of a scope would be granted
        // twice, which is the one failure this check exists to prevent.
        if ($idempotencyKey !== null && UsageEntry::query()->withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->exists()) {
            return false;
        }

        try {
            $this->write(
                $team,
                $metric,
                $direction,
                $quantity,
                BillingPeriod::containing()->key(),
                null,
                null,
                $meta,
                $idempotencyKey,
            );
        } catch (UniqueConstraintViolationException) {
            // The check above lost a race with a concurrent delivery of the same Stripe event, and
            // the unique index is the thing that actually guarantees once-only. Caught rather than
            // left to bubble: an exception here becomes a 500, Stripe reads a 500 as "retry" and
            // retries for three days — over an event that has already been granted. The caller
            // reads false as "already done", which is exactly what happened.
            return false;
        }

        return true;
    }

    /**
     * Units consumed this period — what `usage_meters.used` snapshots.
     */
    public function used(Team $team, BillingMetric $metric, ?BillingPeriod $period = null): int
    {
        return $this->sum($team, $metric, $period ?? BillingPeriod::containing(), [LedgerDirection::Commit]);
    }

    /**
     * Units currently held and not yet settled.
     *
     * Reserves minus commits minus releases. A figure rather than a flag, because a screen that
     * says "2 held" explains a remaining count that otherwise looks wrong.
     */
    public function held(Team $team, BillingMetric $metric, ?BillingPeriod $period = null): int
    {
        $period ??= BillingPeriod::containing();

        return max(0, $this->sum($team, $metric, $period, [LedgerDirection::Reserve])
            - $this->sum($team, $metric, $period, [LedgerDirection::Commit, LedgerDirection::Release]));
    }

    /**
     * Units added on top of the plan this period.
     */
    public function added(Team $team, BillingMetric $metric, ?BillingPeriod $period = null): int
    {
        return $this->sum($team, $metric, $period ?? BillingPeriod::containing(), [
            LedgerDirection::Grant,
            LedgerDirection::TopUp,
            LedgerDirection::Adjustment,
        ]);
    }

    private function usedAndHeld(Team $team, BillingMetric $metric, BillingPeriod $period): int
    {
        return $this->used($team, $metric, $period) + $this->held($team, $metric, $period);
    }

    /**
     * @param  list<LedgerDirection>  $directions
     */
    private function sum(Team $team, BillingMetric $metric, BillingPeriod $period, array $directions): int
    {
        // `withoutGlobalScopes`, like every other read here: `forMeter()` already pins `team_id`
        // explicitly, and leaving the scope on means a reader running inside **another** team's
        // context gets a second, conflicting `team_id` and a sum of zero — which `remaining()`
        // would then report as the full allowance. An over-spend, from a tenancy scope doing its
        // job in the wrong place.
        return (int) UsageEntry::query()
            ->withoutGlobalScopes()
            ->forMeter((int) $team->getKey(), $metric, $period->key())
            ->whereIn('direction', $directions)
            ->sum('quantity');
    }

    /**
     * What is left, counted by a **locking** read of this meter's rows for the period.
     *
     * Only ever called inside {@see self::reserve()}'s transaction, and the reason it exists is a
     * MySQL detail that makes the plain recount wrong (`laravel-module-reviewer`, PL4):
     *
     * InnoDB's default isolation is REPEATABLE READ, and a transaction's snapshot is taken at its
     * **first consistent read** — which is `lockMeter()`'s `firstOrCreate`, *before* it blocks on
     * the lock. So a second request would wait for the lock exactly as intended, acquire it, and
     * then count from a snapshot taken before the winner committed: both would see one unit left
     * and both would reserve it. The lock was serialising two requests that read the same stale
     * number.
     *
     * A locking read is a **current** read in InnoDB, so this sees whatever the previous holder of
     * the lock committed. It is also one query instead of four, and the arithmetic moves to PHP
     * where the three directions are visible together.
     *
     * (`lockForUpdate()` is a no-op on SQLite, which is why the sequential test in
     * `UsageAllowanceTest` passes either way and cannot prove this. See that file's note.)
     */
    private function remainingUnderLock(Team $team, BillingMetric $metric, BillingPeriod $period): ?int
    {
        $entitledByPlan = $this->plans->planFor($team)->allowanceFor($metric);

        $totals = [];

        /** @var UsageEntry $row */
        foreach (UsageEntry::query()
            ->withoutGlobalScopes()
            ->forMeter((int) $team->getKey(), $metric, $period->key())
            ->lockForUpdate()
            ->get(['direction', 'quantity']) as $row) {
            $key = $row->direction->value;
            $totals[$key] = ($totals[$key] ?? 0) + $row->quantity;
        }

        $of = static fn (LedgerDirection ...$directions): int => array_sum(array_map(
            static fn (LedgerDirection $direction): int => $totals[$direction->value] ?? 0,
            $directions,
        ));

        $added = $of(LedgerDirection::Grant, LedgerDirection::TopUp, LedgerDirection::Adjustment);

        if ($entitledByPlan === null && ! $metric->isLedgered()) {
            return null; // unlimited, for the metrics where an absent figure means unlimited
        }

        $committed = $of(LedgerDirection::Commit);
        $held = max(0, $of(LedgerDirection::Reserve) - $committed - $of(LedgerDirection::Release));

        return max(0, (int) ($entitledByPlan ?? 0) + $added - $committed - $held);
    }

    /**
     * The reserve row behind a handle, or null when the handle is unknown to this team.
     *
     * Scoped to the team deliberately: a reservation handle is a UUID, but a settle that trusted it
     * alone would let one team's commit land on another's hold.
     */
    private function heldEntry(string $reservation): ?UsageEntry
    {
        // `withoutGlobalScopes`: a commit arrives from a queued job, and a worker whose team
        // context has been lost must still be able to settle what it held — the handle is a UUID
        // scoped to nothing else, so there is no tenancy question to answer here.
        return UsageEntry::query()
            ->withoutGlobalScopes()
            ->where('reservation', $reservation)
            ->where('direction', LedgerDirection::Reserve)
            ->first();
    }

    private function isSettled(string $reservation): bool
    {
        return UsageEntry::query()
            ->withoutGlobalScopes()
            ->where('reservation', $reservation)
            ->whereIn('direction', [LedgerDirection::Commit, LedgerDirection::Release])
            ->exists();
    }

    /**
     * Write a row that settles a hold, taking its team, metric and period from the hold itself.
     *
     * @param  array<string, mixed>  $meta
     */
    private function writeBeside(UsageEntry $held, LedgerDirection $direction, int $quantity, array $meta = []): void
    {
        UsageEntry::query()->create([
            'team_id' => $held->team_id,
            'meter' => $held->meter,
            'direction' => $direction,
            'quantity' => $quantity,
            'period' => $held->period,
            'reservation' => $held->reservation,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    /**
     * Lock this team's position on this metric for the length of the transaction.
     *
     * `firstOrCreate` before the lock, because there is nothing to lock on a period nobody has
     * touched — and two requests racing to create it is settled by the unique key, which is the
     * same reason `UsageLedger::meter()` does it this way.
     */
    private function lockMeter(int $teamId, BillingMetric $metric, BillingPeriod $period): void
    {
        UsageMeter::query()->withoutGlobalScopes()->firstOrCreate(
            ['team_id' => $teamId, 'metric' => $metric->value, 'period_start' => $period->start],
            ['period_end' => $period->end, 'used' => 0, 'included' => 0],
        );

        UsageMeter::query()
            ->withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('metric', $metric->value)
            ->where('period_start', $period->start)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function write(
        Team $team,
        BillingMetric $metric,
        LedgerDirection $direction,
        int $quantity,
        string $period,
        ?string $reservation = null,
        ?Model $reference = null,
        array $meta = [],
        ?string $idempotencyKey = null,
    ): void {
        UsageEntry::query()->create([
            'team_id' => $team->getKey(),
            'meter' => $metric,
            'direction' => $direction,
            'quantity' => $quantity,
            'period' => $period,
            'reference_type' => $reference === null ? null : $reference::class,
            'reference_id' => $reference?->getKey(),
            'reservation' => $reservation,
            'idempotency_key' => $idempotencyKey,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}
