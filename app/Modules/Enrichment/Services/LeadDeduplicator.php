<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Services;

use App\Modules\Enrichment\Data\SourcedValue;
use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Enums\LeadStatus;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Collapses leads that enrichment has revealed to be the same company (Phase 8, ADR 0008). Discovery
 * de-dupes on a single `dedupe_key`, but once enrichment fills in a website/registry number/contact
 * email a lead can turn out to duplicate another that was keyed differently.
 *
 * Matching is deliberately conservative to avoid false merges driven by scraped (untrusted) page
 * content: an exact registry number always counts; a website domain counts unless it is a known
 * aggregator/social host; a shared contact email counts only when it is routable, on a non-free-mail
 * domain, AND on the processing lead's own website domain (so a hostile page that injects
 * `info@victim.com` can't fold an unrelated company in). Two records with conflicting registry
 * numbers are never merged on domain/email alone.
 *
 * The survivor is the most authoritative record ({@see LeadSource::rank()},
 * oldest on a tie); each duplicate is merged into it (fill empty fields, never null a value, record
 * provenance), its contacts and enrichment bookkeeping are carried across, and it is deleted. Runs
 * inside the caller's transaction and locks the whole group in id order to stay deadlock-safe under
 * the per-run enrichment fan-out.
 */
final class LeadDeduplicator
{
    /**
     * Merge every duplicate of `$lead` into one surviving record and return it (which may be `$lead`
     * itself when nothing matched). The passed model may be deleted if a stronger duplicate wins, or
     * may already have been merged away by a concurrent run — in which case the current survivor is
     * returned.
     */
    public function deduplicate(Lead $lead): Lead
    {
        $duplicates = $this->findDuplicates($lead);

        if ($duplicates === []) {
            return $lead;
        }

        // Lock the whole candidate group in a stable (id) order so two concurrent enrichments of the
        // same run can't deadlock or half-merge. Rows that vanished since the scan simply drop out.
        $ids = array_map(static fn (Lead $l): int => (int) $l->getKey(), [$lead, ...$duplicates]);
        sort($ids);

        $group = array_values(Lead::query()
            ->where('team_id', $lead->team_id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->all());

        if (count($group) <= 1) {
            // Everything but (at most) one row was merged away concurrently; return what remains.
            return $group[0] ?? $lead;
        }

        $survivor = $this->pickSurvivor($group);

        foreach ($group as $other) {
            if ($other->getKey() === $survivor->getKey()) {
                continue;
            }

            $this->mergeInto($survivor, $other);
            $this->moveContacts($survivor, $other);
            $other->delete();
        }

        $this->refreshDedupeKey($survivor);
        $survivor->save();

        return $survivor;
    }

    /**
     * Other leads in the team that are the same company as `$lead`, by exact number, a non-aggregator
     * website domain, or a same-domain routable contact email. Candidates with a conflicting registry
     * number are dropped (unless matched on that number itself).
     *
     * @return list<Lead>
     */
    private function findDuplicates(Lead $lead): array
    {
        $teamId = $lead->team_id;
        $selfId = $lead->getKey();
        $matches = [];

        // --- By registry number (strongest; always allowed) ---
        $number = trim((string) $lead->company_number);
        if ($number !== '') {
            foreach (Lead::query()
                ->where('team_id', $teamId)
                ->whereKeyNot($selfId)
                ->whereRaw('LOWER(company_number) = ?', [Str::lower($number)])
                ->get() as $match) {
                $matches[(int) $match->getKey()] = $match;
            }
        }

        // --- By website domain (unless it's a shared aggregator/social host) ---
        $domain = $lead->domain();
        if ($domain !== null && ! $this->isBlockedDomain($domain)) {
            foreach (Lead::query()
                ->where('team_id', $teamId)
                ->whereKeyNot($selfId)
                ->where('website', 'like', '%'.$domain.'%')
                ->get() as $match) {
                if ($match->domain() === $domain && ! $this->conflictsOnNumber($lead, $match)) {
                    $matches[(int) $match->getKey()] = $match;
                }
            }
        }

        // --- By a shared contact email on the lead's own domain (routable, non-free-mail) ---
        $emails = $this->matchableEmails($lead, $domain);
        if ($emails !== []) {
            $leadIds = LeadContact::query()
                ->where('team_id', $teamId)
                ->where('lead_id', '!=', $selfId)
                ->whereIn('email', $emails)
                ->pluck('lead_id')
                ->unique()
                ->all();

            if ($leadIds !== []) {
                foreach (Lead::query()
                    ->where('team_id', $teamId)
                    ->whereIn('id', $leadIds)
                    ->get() as $match) {
                    if (! $this->conflictsOnNumber($lead, $match)) {
                        $matches[(int) $match->getKey()] = $match;
                    }
                }
            }
        }

        return array_values($matches);
    }

    /**
     * The lead's own contact emails usable as an identity signal: routable (MX found), not on a
     * blocked/free-mail domain, and on the lead's own website domain. Returns [] when the lead has no
     * website domain, so a scraped foreign address can never fold in an unrelated company.
     *
     * @return list<string>
     */
    private function matchableEmails(Lead $lead, ?string $domain): array
    {
        if ($domain === null || $this->isBlockedDomain($domain)) {
            return [];
        }

        return array_values($lead->contacts()
            ->where('email_verification_status', EmailVerificationStatus::MxFound->value)
            ->whereNotNull('email')
            ->pluck('email')
            ->map(static fn (string $e): string => Str::lower($e))
            ->filter(fn (string $e): bool => $this->emailDomain($e) === $domain)
            ->unique()
            ->all());
    }

    /**
     * Both leads carry a non-empty registry number and the numbers differ — never a true duplicate.
     */
    private function conflictsOnNumber(Lead $a, Lead $b): bool
    {
        $na = Str::lower(trim((string) $a->company_number));
        $nb = Str::lower(trim((string) $b->company_number));

        return $na !== '' && $nb !== '' && $na !== $nb;
    }

    /**
     * The record that keeps its identity: highest source rank, oldest (lowest id) on a tie.
     *
     * @param  list<Lead>  $group
     */
    private function pickSurvivor(array $group): Lead
    {
        $survivor = $group[0];

        foreach ($group as $lead) {
            $rank = $lead->source->rank();
            $bestRank = $survivor->source->rank();

            if ($rank > $bestRank || ($rank === $bestRank && (int) $lead->getKey() < (int) $survivor->getKey())) {
                $survivor = $lead;
            }
        }

        return $survivor;
    }

    /**
     * Merge `$other` into `$survivor`. The survivor is always the most authoritative record
     * ({@see pickSurvivor}), so a populated field is never overwritten — only the survivor's *empty*
     * fields are filled from the duplicate, each with a provenance entry — and no field is ever
     * nulled. Enrichment bookkeeping (status, freshness, cost) is promoted so the survivor never
     * regresses, and `has_personal_data` is OR-ed.
     */
    private function mergeInto(Lead $survivor, Lead $other): void
    {
        $fields = [
            'company_name', 'company_number', 'company_status', 'company_type', 'industry',
            'employee_range', 'technologies', 'incorporated_on', 'website', 'locality', 'region',
            'postal_code', 'country', 'address', 'sic_codes', 'source_url', 'raw',
        ];

        /** @var array<string, SourcedValue> $sources */
        $sources = $survivor->field_sources;

        foreach ($fields as $field) {
            if (! $this->isEmpty($survivor->getAttribute($field)) || $this->isEmpty($other->getAttribute($field))) {
                continue; // survivor already has a value, or the duplicate has nothing to give
            }

            $survivor->setAttribute($field, $other->getAttribute($field));
            // Carry the duplicate's provenance for this field, or stamp a merge record.
            $sources[$field] = $other->field_sources[$field] ?? new SourcedValue(
                source: $other->source->value,
                method: SourcedValue::METHOD_MERGE,
                collectedAt: Carbon::now(),
            );
        }

        // Union any remaining provenance the survivor didn't already have.
        foreach ($other->field_sources as $key => $sourced) {
            if (! array_key_exists($key, $sources)) {
                $sources[$key] = $sourced;
            }
        }
        $survivor->field_sources = $sources;

        $this->promoteBookkeeping($survivor, $other);
    }

    /**
     * Ensure the survivor reflects the furthest progress of the group: never downgrade its enrichment
     * or pipeline state, keep the latest freshness stamp, and sum the metered cost so accounting
     * isn't lost when a row is deleted.
     */
    private function promoteBookkeeping(Lead $survivor, Lead $other): void
    {
        $enrichmentOrder = [
            EnrichmentStatus::Pending->value => 0,
            EnrichmentStatus::Enriching->value => 1,
            EnrichmentStatus::Failed->value => 2,
            EnrichmentStatus::Enriched->value => 3,
        ];
        if ($enrichmentOrder[$other->enrichment_status->value] > $enrichmentOrder[$survivor->enrichment_status->value]) {
            $survivor->enrichment_status = $other->enrichment_status;
        }

        $statusOrder = [
            LeadStatus::Discovered->value => 0,
            LeadStatus::Enriched->value => 1,
            LeadStatus::Scored->value => 2,
        ];
        if ($statusOrder[$other->status->value] > $statusOrder[$survivor->status->value]) {
            $survivor->status = $other->status;
        }

        if ($other->enriched_at !== null && ($survivor->enriched_at === null || $other->enriched_at->greaterThan($survivor->enriched_at))) {
            $survivor->enriched_at = $other->enriched_at;
        }

        $survivor->enrichment_cost_micros += $other->enrichment_cost_micros;
        $survivor->has_personal_data = $survivor->has_personal_data || $other->has_personal_data;
    }

    /**
     * Re-parent `$other`'s contacts onto `$survivor`, dropping any whose email the survivor already
     * has (those stay on `$other` and are removed when it is deleted, honouring the per-lead unique
     * email). A null-email (phone-only) contact always moves across.
     */
    private function moveContacts(Lead $survivor, Lead $other): void
    {
        $existing = $survivor->contacts()
            ->whereNotNull('email')
            ->pluck('email')
            ->map(static fn (string $e): string => Str::lower($e))
            ->all();

        foreach ($other->contacts()->get() as $contact) {
            $email = $contact->email !== null ? Str::lower($contact->email) : null;

            if ($email !== null && in_array($email, $existing, strict: true)) {
                continue; // survivor already has this address
            }

            $contact->forceFill(['lead_id' => $survivor->getKey()])->save();

            if ($email !== null) {
                $existing[] = $email;
            }
        }
    }

    /**
     * Give the survivor the strongest identity key its merged fields now support (number → domain),
     * so future discovery de-dupes against it. Left unchanged if another lead already holds that key.
     */
    private function refreshDedupeKey(Lead $survivor): void
    {
        $key = null;

        $number = trim((string) $survivor->company_number);
        if ($number !== '') {
            $key = 'no:'.Str::lower($number);
        } elseif (($domain = $survivor->domain()) !== null) {
            $key = 'domain:'.$domain;
        }

        if ($key === null || $key === $survivor->dedupe_key) {
            return;
        }

        $taken = Lead::query()
            ->where('team_id', $survivor->team_id)
            ->whereKeyNot($survivor->getKey())
            ->where('dedupe_key', $key)
            ->exists();

        if (! $taken) {
            $survivor->dedupe_key = $key;
        }
    }

    private function emailDomain(string $email): ?string
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return null;
        }

        $domain = Str::lower(substr($email, $at + 1));

        return $domain === '' ? null : $domain;
    }

    private function isBlockedDomain(string $domain): bool
    {
        /** @var list<string> $blocked */
        $blocked = config('enrichment.blocked_dedupe_domains', []);

        return in_array($domain, array_map(static fn (string $d): string => Str::lower($d), $blocked), strict: true);
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
