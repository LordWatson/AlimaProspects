<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Core\Enums\OutreachRestriction;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\LeadsWorkspace\Models\LeadNote;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Signals\Models\Signal;
use Illuminate\Support\Facades\DB;

/**
 * Strips the personal data out of one lead while keeping the company record and the team's own
 * account of what it did (docs/compliance.md §3.4).
 *
 * This is the half of retention that is a judgement rather than a `delete()`, so the list is
 * explicit rather than inferred. **What goes:**
 *
 * - every **contact** on the lead — the names, titles, addresses and numbers;
 * - the **text of every drafted or sent message**, and the address it was sent to;
 * - the **note on every logged approach**, which is free text a member may have put anything in;
 * - the team's **notes and tasks** on the lead, for the same reason;
 * - the free-text values carried in **timeline entries** (a note excerpt, a task title, a news
 *   headline), leaving the entry, its type and its date;
 * - the **news signals** this lead was converted from, which hold a named individual's name and
 *   role (§5a);
 * - the **provider payload** in `raw`, which can contain anything a register or a places API chose
 *   to return.
 *
 * **What stays:** the company's own facts, its score, its stage, and one row per approach carrying
 * the channel, the outcome and the date. That is the team's evidence of who they contacted and
 * when — the accountability the retention rule sits beside, not something to trade for a tidier
 * database.
 *
 * Two details that look small and are not:
 *
 * - **The outreach restriction survives `raw` being cleared.** It lives in `raw` (ADR 0011) and
 *   docs/compliance.md §5a is explicit that a restriction may be added to a lead but never dropped
 *   from one. Clearing the key would quietly reopen cold email on a lead that must never have it.
 * - **Signals are deleted rather than scrubbed.** A signal's own value is its headline and source
 *   link, and both name the person; scrubbing it would leave a provenance record that proves
 *   nothing. The lead keeps its `source` and `source_url` attribution, which is what a team needs
 *   to answer "where did this come from".
 *
 * Idempotent: `anonymised_at` is the stamp the sweep filters on, so a lead is never processed twice.
 */
final class LeadAnonymiser
{
    /**
     * Free-text keys a timeline entry may carry. Stripped by name rather than by clearing
     * `properties` wholesale, because the rest of it — the grade, the old and new stage, the
     * channel, the counts — is what the timeline renders its sentence from, and a blank entry would
     * be worse for the team than no entry at all.
     *
     * @var list<string>
     */
    private const array FREE_TEXT_PROPERTIES = ['excerpt', 'title', 'headline'];

    /**
     * Anonymise one lead, in a single transaction. Returns false when it had already been done,
     * so a caller's count stays honest.
     */
    public function handle(Lead $lead): bool
    {
        if ($lead->anonymised_at !== null) {
            return false;
        }

        $leadId = (int) $lead->getKey();

        DB::transaction(function () use ($lead, $leadId): void {
            LeadContact::query()->withoutGlobalScopes()->where('lead_id', $leadId)->delete();

            // The message text named the recipient; `sent_to` *is* their address. Status, channel,
            // provider id and `sent_at` stay — that is the record that something was sent.
            OutreachMessage::query()->withoutGlobalScopes()->where('lead_id', $leadId)->update([
                'subject' => null,
                'body' => '',
                'variants' => null,
                'sent_to' => null,
                'error_message' => null,
            ]);

            ContactAttempt::query()->withoutGlobalScopes()->where('lead_id', $leadId)->update(['notes' => null]);

            LeadNote::query()->withoutGlobalScopes()->where('lead_id', $leadId)->delete();
            LeadTask::query()->withoutGlobalScopes()->where('lead_id', $leadId)->delete();

            $this->scrubTimeline($leadId);

            // The provenance of a converted lead (§5a). Deleted rather than scrubbed: a signal is
            // its headline and its link, and both name the person.
            Signal::query()->withoutGlobalScopes()->where('lead_id', $leadId)->delete();

            $lead->forceFill([
                // Keep the restriction, drop everything else the provider sent.
                'raw' => $this->preservedRaw($lead),
                'field_sources' => [],
                'has_personal_data' => false,
                'anonymised_at' => now(),
            ])->save();
        });

        return true;
    }

    /**
     * Everything that must survive `raw` being emptied — today, the outreach restriction and
     * nothing else. Returns null rather than an empty array when there is nothing to keep, so the
     * column reads as "no payload" rather than "a payload with no keys".
     *
     * @return array<string, mixed>|null
     */
    private function preservedRaw(Lead $lead): ?array
    {
        $restriction = OutreachRestriction::fromRaw($lead->raw);

        return $restriction === null
            ? null
            : [OutreachRestriction::RAW_KEY => $restriction->value];
    }

    /**
     * Remove the free-text values from this lead's timeline entries, leaving the structure.
     *
     * Done row by row rather than in one `update`, because the column is JSON and the keys to drop
     * differ per row; the number of entries on one lead is small and bounded by the work the team
     * actually did on it.
     */
    private function scrubTimeline(int $leadId): void
    {
        $entries = LeadActivity::query()
            ->withoutGlobalScopes()
            ->where('lead_id', $leadId)
            ->whereNotNull('properties')
            ->get();

        foreach ($entries as $entry) {
            $properties = $entry->properties ?? [];
            $cleaned = array_diff_key($properties, array_flip(self::FREE_TEXT_PROPERTIES));

            if ($cleaned === $properties) {
                continue;
            }

            $entry->forceFill(['properties' => $cleaned === [] ? null : $cleaned])->save();
        }
    }
}
