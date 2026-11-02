<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Platform;

use App\Modules\Admin\Actions\AddPlatformSuppressionAction;
use App\Modules\Admin\Enums\PlatformMetric;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Models\OutreachSuppression;
use App\Modules\Outreach\Services\OutreachGuard;
use App\Modules\Outreach\Services\SuppressionList;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Reporting\Support\Ratio;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Platform outreach oversight (Phase 19c): send volume, deliverability, and the **global
 * do-not-contact list**.
 *
 * ## Why the platform cares at all
 *
 * Because a shared sending domain means **one tenant's behaviour is every tenant's
 * deliverability**. A tenant working a bought list raises the complaint rate on a domain every
 * other customer sends from, and the first anybody knows about it is mail stopping. The volume and
 * complaint figures here are what make that visible before a provider notices it.
 *
 * ## The global list is the same table, with a null `team_id`
 *
 * Not a second table, and that matters more than it sounds: `outreach_suppressions` is **the**
 * do-not-contact list (CLAUDE.md §3), {@see OutreachGuard} is the only thing that answers whether a
 * prospect may be approached, and a platform list kept anywhere else would be a list the guard does
 * not read. A `global`-scoped entry has `team_id` null by construction and
 * {@see SuppressionList::anySuppressed()} already includes the global scope in every send-time
 * check for every tenant — so the enforcement this screen relies on was built in Phase 11 and
 * needs nothing new.
 *
 * Writing is additive only. There is **no lift route** for a global entry, here or anywhere:
 * {@see OutreachSuppression::isRemovable()} refuses one, the policy refuses one, and the panel
 * declines to offer one. Removing a global suppression would mean overruling a person's stated wish
 * with a button, which is the one thing this table exists to make impossible.
 */
final class OutreachOversight
{
    /**
     * Only the metric store. Writing a global entry is
     * {@see AddPlatformSuppressionAction}'s job, through
     * {@see SuppressionList} — this class reads, so that there is exactly one thing in the panel
     * that can add to the do-not-contact list and exactly one place its audit entry is written.
     */
    public function __construct(private readonly PlatformMetricStore $store) {}

    /**
     * Send volume and deliverability by channel over the window, from the rollup.
     *
     * @return array<string, mixed>
     */
    public function deliverability(DateRange $range): array
    {
        $sent = $this->store->byDimension(PlatformMetric::OutreachSent, $range);
        $attempts = $this->store->byDimension(PlatformMetric::OutreachAttempts, $range);
        $outcomes = $this->store->byDimension(PlatformMetric::OutreachOutcomes, $range);

        $channels = [];
        $totals = ['sent' => 0, 'attempts' => 0, 'bounced' => 0, 'complaints' => 0];

        foreach (OutreachChannel::cases() as $channel) {
            $key = $channel->value;
            $logged = $attempts[$key] ?? 0;
            $bounced = $outcomes[$key.':'.ContactOutcome::Bounced->value] ?? 0;
            // An opt-out *is* a complaint in substance for deliverability purposes — somebody asked
            // to be left alone — and `SuppressionReason::Complaint` says the same thing about a
            // spam report. The outcome enum has no separate complaint case, so the opt-out count is
            // the signal, and the label on the screen says so rather than implying a spam report.
            $optedOut = $outcomes[$key.':'.ContactOutcome::OptedOut->value] ?? 0;

            $channels[] = [
                'channel' => $key,
                'label' => $channel->label(),
                'sent' => $sent[$key] ?? 0,
                'attempts' => $logged,
                'bounced' => $bounced,
                'opted_out' => $optedOut,
                'bounce_rate' => Ratio::of($bounced, $logged),
                'opt_out_rate' => Ratio::of($optedOut, $logged),
            ];

            $totals['sent'] += $sent[$key] ?? 0;
            $totals['attempts'] += $logged;
            $totals['bounced'] += $bounced;
            $totals['complaints'] += $optedOut;
        }

        return [
            'channels' => $channels,
            'totals' => $totals + [
                'bounce_rate' => Ratio::of($totals['bounced'], $totals['attempts']),
                'opt_out_rate' => Ratio::of($totals['complaints'], $totals['attempts']),
            ],
            'last_rolled_up' => $this->store->lastRolledUpDay(),
        ];
    }

    /**
     * The global do-not-contact list, newest first.
     *
     * **Previews only**, which is the table's own design: only a SHA-256 of the normalised value is
     *
     * stored, and `value_preview` holds `j***@example.com` or `***4821`. A register of people who
     * want nothing to do with us is not a contact book, and an operations screen is not a reason to
     * make it one.
     *
     * @return LengthAwarePaginator<int, OutreachSuppression>
     */
    public function globalList(int $perPage = 50): LengthAwarePaginator
    {
        return OutreachSuppression::query()
            ->where('scope_key', SuppressionScope::Global->keyFor(null))
            ->with('creator:id,name')
            ->orderByDesc('suppressed_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  LengthAwarePaginator<int, OutreachSuppression>  $page
     * @return list<array<string, mixed>>
     */
    public function rows(LengthAwarePaginator $page): array
    {
        return array_values(array_map(static fn (OutreachSuppression $entry): array => [
            'id' => (int) $entry->getKey(),
            'type' => $entry->type->value,
            'type_label' => $entry->type->label(),
            'preview' => $entry->value_preview,
            'reason' => $entry->reason->value,
            'reason_label' => $entry->reason->label(),
            'note' => $entry->note,
            'added_by' => $entry->creator?->name,
            'suppressed_at' => $entry->suppressed_at->toIso8601String(),
            // Always false for a global entry. Rendered rather than assumed, so the screen's
            // absence of a remove button is visibly a rule and not an oversight.
            'removable' => $entry->isRemovable(),
        ], $page->items()));
    }

    /**
     * How many entries exist at each scope, and the reasons they were added for.
     *
     * The team-scoped count is here for one sentence an operator needs: the global list is tiny and
     * the tenant lists are not, so a number on its own ("4,812 suppressions") would be read as a
     * platform-wide block list and alarm somebody.
     *
     * @return array<string, mixed>
     */
    public function suppressionSummary(): array
    {
        $byScope = OutreachSuppression::query()
            ->selectRaw('scope, COUNT(*) as aggregate')
            ->groupBy('scope')
            ->pluck('aggregate', 'scope')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        $byReason = OutreachSuppression::query()
            ->where('scope_key', SuppressionScope::Global->keyFor(null))
            ->selectRaw('reason, COUNT(*) as aggregate')
            ->groupBy('reason')
            ->pluck('aggregate', 'reason')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        return [
            'global' => $byScope[SuppressionScope::Global->value] ?? 0,
            'team' => $byScope[SuppressionScope::Team->value] ?? 0,
            'global_by_reason' => $byReason,
        ];
    }

    /**
     * The options the add-a-suppression form offers.
     *
     * **Not every reason.** A platform entry records why nobody may contact this identity, and only
     * three of the five readings make sense at platform scope: they asked us to stop, they reported
     * us, or we are adding it by hand. A **bounce** is a per-tenant technical fact, and an
     * **erasure** belongs to the tenant that received the request — offering either here would
     * invite an operator to record a platform-wide fact that is neither.
     *
     * @return array<string, mixed>
     */
    public function formOptions(): array
    {
        $reasons = [SuppressionReason::OptOut, SuppressionReason::Complaint, SuppressionReason::Manual];

        return [
            'types' => array_map(
                static fn (SuppressionType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ],
                SuppressionType::cases(),
            ),
            'reasons' => array_map(
                static fn (SuppressionReason $reason): array => [
                    'value' => $reason->value,
                    'label' => $reason->label(),
                ],
                $reasons,
            ),
        ];
    }

    /**
     * The reasons a platform suppression may be recorded for — the closed list the request
     * validates against, so the form and the validation cannot disagree.
     *
     * @return list<string>
     */
    public static function allowedReasons(): array
    {
        return [
            SuppressionReason::OptOut->value,
            SuppressionReason::Complaint->value,
            SuppressionReason::Manual->value,
        ];
    }
}
