<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Models\LiaRecord;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Contracts\LeadInterestGate;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use Illuminate\Support\Facades\Log;

/**
 * The real LIA gate (Phase 15), replacing Lead Sourcing's `AllowAllLeadInterestGate` binding.
 * Discovery is refused unless the team has an assessment **in force** for the market it is sourcing
 * in (docs/compliance.md §2).
 *
 * Three things about it are deliberate:
 *
 * - **It refuses, rather than warning.** The assessment is the lawful basis on which a sourced lead
 *   is allowed to exist at all. A gate that logged its absence and carried on would leave the
 *   platform holding personal data with nothing to point at — which is the failure the recorded
 *   assessment exists to prevent, and it would be our failure as processor rather than the team's.
 * - **It is keyed on the team's market**, the same regime the leads will be worked under. ADR 0007
 *   gives the UK and the UAE different postures, and one assessment cannot honestly cover both.
 * - **It distinguishes "none" from "expired".** Both close the gate, and the member is told
 *   different things: write one, or re-affirm the one you have. A single "no basis" message would
 *   send a team with a lapsed review off to rewrite an assessment they had already done.
 *
 * Read inside {@see TeamContext} so the tenant scope applies: a gate that could see another team's
 * assessment would be a gate that opens on somebody else's paperwork.
 */
final class LegitimateInterestGate implements LeadInterestGate
{
    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * @throws LeadSourcingException when no assessment is in force for the team's market
     */
    public function assertDiscoveryAllowed(Team $team): void
    {
        $record = $this->recordFor($team);

        if ($record !== null && $record->isInForce()) {
            return;
        }

        // Ids, enums and counts only — never the assessment's text (CLAUDE.md logging rules).
        Log::channel('compliance')->warning('Discovery refused: no legitimate-interest assessment in force.', [
            'team_id' => (int) $team->getKey(),
            'market' => $this->marketFor($team)->value,
            'lia_status' => $record?->status->value,
            'expired' => $record?->hasExpired() ?? false,
        ]);

        throw $record !== null && $record->hasExpired()
            ? LeadSourcingException::interestAssessmentExpired()
            : LeadSourcingException::interestAssessmentRequired();
    }

    /**
     * Whether the gate is currently open, without throwing — what the discovery screen and the
     * compliance centre ask so they can say so before a member fills in a form.
     */
    public function isOpen(Team $team): bool
    {
        return $this->recordFor($team)?->isInForce() ?? false;
    }

    /**
     * The team's assessment for its own market, or null when there is none.
     */
    public function recordFor(Team $team, ?Market $market = null): ?LiaRecord
    {
        $market ??= $this->marketFor($team);

        return $this->teamContext->run($team, fn (): ?LiaRecord => LiaRecord::query()
            ->where('team_id', $team->getKey())
            ->where('market', $market->value)
            ->first());
    }

    /**
     * The team's assessment for every market it might source in, keyed by market value — what the
     * compliance centre lists. A market with no record is absent rather than null, so the screen
     * decides how to word "not started".
     *
     * @return array<string, LiaRecord>
     */
    public function recordsFor(Team $team): array
    {
        $records = $this->teamContext->run($team, fn (): array => LiaRecord::query()
            ->where('team_id', $team->getKey())
            ->get()
            ->all());

        $byMarket = [];

        foreach ($records as $record) {
            $byMarket[$record->market->value] = $record;
        }

        return $byMarket;
    }

    /**
     * The market a team's discovery is assessed under. Falls back to the UK rather than throwing on
     * an unrecognised column value: an assessment is checked on the way into a revenue-generating
     * action, and a stale enum string must close the gate on a known regime rather than 500.
     */
    public function marketFor(Team $team): Market
    {
        return Market::tryFrom($team->market) ?? Market::Uk;
    }

    /**
     * Whether a saved assessment holds together well enough to be signed off. Used by the Action
     * before it stamps `completed_at`, so a perfunctory record cannot open the gate.
     *
     * The three tests are required because they are the assessment; `safeguards` and the Signals
     * section are not, because a team with no watches has nothing to say in the second and the
     * first is a summary of measures recorded elsewhere.
     *
     * It asks about **content only**, deliberately. A withdrawn record is signable: re-affirming
     * one is the documented way back — the withdrawal is cleared and the version moves on — and a
     * status check here would have made the only route out of a withdrawal deleting the row.
     */
    public function isSignable(LiaRecord $record): bool
    {
        $minimum = max(1, (int) config('compliance.lia.section_minimum', 40));

        foreach ([$record->purpose, $record->necessity, $record->balancing] as $section) {
            if (mb_strlen(trim($section)) < $minimum) {
                return false;
            }
        }

        return true;
    }
}
