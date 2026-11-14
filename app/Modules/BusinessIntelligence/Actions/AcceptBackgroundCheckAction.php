<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Actions;

use App\Models\User;
use App\Modules\BusinessIntelligence\Data\AcceptBackgroundCheckData;
use App\Modules\BusinessIntelligence\Data\BackgroundCheckFindings;
use App\Modules\BusinessIntelligence\Enums\BackgroundCheckStatus;
use App\Modules\BusinessIntelligence\Exceptions\BackgroundCheckException;
use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;
use App\Modules\BusinessIntelligence\Support\ProfileMapping;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Actions\CreateIcpAction;
use App\Modules\ICP\Data\CriterionData;
use App\Modules\ICP\Data\IcpData;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\Onboarding\Enums\DeliveryModel;
use App\Modules\Onboarding\Models\BusinessProfile;
use Illuminate\Support\Facades\DB;

/**
 * Accept a ready background-check report. Merges its findings into the team's Business Profile —
 * filling blank fields, and replacing what the business itself entered only for a field the member
 * explicitly chose ({@see ProfileMapping}) — and optionally seeds a suggested ICP from the findings.
 * Marks the report accepted. Everything is one transaction.
 */
final class AcceptBackgroundCheckAction
{
    /**
     * How many suggested industries / target customers to turn into ICP criteria (keep it focused).
     */
    private const int MAX_ICP_CRITERIA_PER_TYPE = 5;

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
        private readonly CreateIcpAction $createIcp,
        private readonly ProfileMapping $mapping,
    ) {}

    public function handle(Team $team, BusinessIntelReport $report, AcceptBackgroundCheckData $data, ?User $actor = null): BusinessIntelReport
    {
        if (! $report->isReady()) {
            throw BackgroundCheckException::notReviewable();
        }

        return $this->teamContext->run($team, fn (): BusinessIntelReport => DB::transaction(function () use ($team, $report, $data, $actor): BusinessIntelReport {
            $fresh = BusinessIntelReport::query()
                ->where('team_id', $team->getKey())
                ->whereKey($report->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $fresh->isReady()) {
                throw BackgroundCheckException::notReviewable();
            }

            $findings = BackgroundCheckFindings::fromAi($fresh->findings ?? []);

            $written = $data->applyToProfile ? $this->mergeIntoProfile($team, $findings, $data->overrides) : [];

            if ($data->seedIcp) {
                $this->seedIcp($team, $findings, $actor);
            }

            $fresh->forceFill([
                'status' => BackgroundCheckStatus::Accepted,
                'accepted_at' => now(),
                'accepted_by' => $actor?->getKey(),
            ])->save();

            $this->audit->log('business_intel.accepted', $fresh, [
                'version' => $fresh->version,
                'applied_to_profile' => $data->applyToProfile,
                // Which fields changed, and which of those replaced something the team had typed.
                'profile_fields_written' => $written,
                'profile_fields_overridden' => array_values(array_intersect($written, $data->overrides)),
                'seeded_icp' => $data->seedIcp,
            ], $actor);

            return $fresh;
        }));
    }

    /**
     * Write the findings onto the Business Profile: every blank field, and — only where the member
     * chose it — a field the team had already filled in differently. What is written is exactly
     * what {@see ProfileMapping} showed them on the review screen.
     *
     * @param  list<string>  $overrides
     * @return list<string> the profile fields written
     */
    private function mergeIntoProfile(Team $team, BackgroundCheckFindings $findings, array $overrides): array
    {
        $profile = BusinessProfile::query()
            ->where('team_id', $team->getKey())
            ->lockForUpdate()
            ->first();

        return $profile instanceof BusinessProfile
            ? $this->mapping->apply($profile, $findings, $overrides)
            : [];
    }

    /**
     * Build a suggested ICP from the findings and persist it via the ICP module's create Action.
     *
     * An ICP describes the team's **customers**, so only the customer-side findings may become
     * signals here. `ownIndustry` and `competitors` go in as {@see CriterionType::Exclusion} instead:
     * they name the companies the team must never be shown, and an exclusion is read as a hard
     * disqualifier by the scorer and as a negative search term by discovery.
     *
     * Before this was split, the single `suggested_industries` list became the industry signal, which
     * meant the team's own trade *was* its ICP — so discovery went looking for its competitors. If
     * the check found no customer-side signal at all we seed nothing: a wrong ICP is worse than no
     * ICP, because discovery and scoring both act on it silently.
     */
    private function seedIcp(Team $team, BackgroundCheckFindings $findings, ?User $actor): void
    {
        if (! $findings->hasCustomerSignals()) {
            return;
        }

        $criteria = [];

        foreach (array_slice($findings->customerIndustries, 0, self::MAX_ICP_CRITERIA_PER_TYPE) as $industry) {
            $criteria[] = new CriterionData(type: CriterionType::Industry, value: $industry, weight: 60);
        }

        foreach (array_slice($findings->decisionMakerTitles, 0, self::MAX_ICP_CRITERIA_PER_TYPE) as $title) {
            $criteria[] = new CriterionData(type: CriterionType::JobTitle, value: $title, weight: 40);
        }

        if ($criteria === []) {
            // Only the prose-y `targetCustomers` came back. It describes buyers, so it is worth
            // keeping, but as an industry signal rather than a job title — "Early-stage startups" is
            // a kind of company, not somebody's role.
            foreach (array_slice($findings->targetCustomers, 0, self::MAX_ICP_CRITERIA_PER_TYPE) as $kind) {
                $criteria[] = new CriterionData(type: CriterionType::Industry, value: $kind, weight: 50);
            }
        }

        if ($criteria === []) {
            return;
        }

        // What the site says beyond who buys (relevance P3/P4): how big they are, how far away
        // they can be, and what makes one ready. Each is a criterion the profile would otherwise
        // leave to the member to think of.
        if ($findings->customerSizeRange !== null) {
            $criteria[] = new CriterionData(type: CriterionType::CompanySize, value: $findings->customerSizeRange, weight: 50);
        }

        // Only a business that has to turn up in person is tied to where it is based.
        $base = BusinessProfile::query()->where('team_id', $team->getKey())->value('locations');
        if (in_array($findings->deliveryModel, [DeliveryModel::Local, DeliveryModel::Regional], true) && is_array($base) && isset($base[0])) {
            $criteria[] = new CriterionData(type: CriterionType::Geography, value: (string) $base[0], weight: 55);
        }

        foreach (array_slice($findings->buyingTriggers, 0, 3) as $trigger) {
            $criteria[] = new CriterionData(type: CriterionType::BuyingSignal, value: $trigger, weight: 35);
        }

        foreach ($this->exclusions($findings) as $exclusion) {
            $criteria[] = new CriterionData(type: CriterionType::Exclusion, value: $exclusion, weight: null);
        }

        $this->createIcp->handle($team, new IcpData(
            name: 'Suggested from background check',
            description: $this->icpDescription($findings),
            isActive: false,
            criteria: $criteria,
        ), $actor);
    }

    /**
     * What this ICP should never match: the team's own trade first, then any named competitor.
     *
     * @return list<string>
     */
    private function exclusions(BackgroundCheckFindings $findings): array
    {
        $values = [];

        if ($findings->ownIndustry !== null) {
            $values[] = $findings->ownIndustry;
        }

        foreach (array_slice($findings->competitors, 0, self::MAX_ICP_CRITERIA_PER_TYPE) as $competitor) {
            $values[] = $competitor;
        }

        // And who the business itself says it does not serve.
        foreach (array_slice($findings->antiCustomers, 0, self::MAX_ICP_CRITERIA_PER_TYPE) as $antiCustomer) {
            $values[] = $antiCustomer;
        }

        // Case-insensitive de-dupe, original casing kept — a competitor is often named after the
        // trade it is in, so the two lists overlap more often than not.
        $seen = [];
        $unique = [];
        foreach ($values as $value) {
            $key = mb_strtolower($value);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $value;
            }
        }

        return $unique;
    }

    /**
     * Say in the ICP's own description who it is aimed at, so a member opening the editor can see
     * what the check inferred and correct it rather than guess at the criteria.
     */
    private function icpDescription(BackgroundCheckFindings $findings): string
    {
        $description = 'Auto-generated from your AI background check. Tune the weights to match who you really want.';

        if ($findings->targetCustomers !== []) {
            $description .= ' It is aimed at '
                .implode(', ', array_slice($findings->targetCustomers, 0, self::MAX_ICP_CRITERIA_PER_TYPE)).'.';
        }

        if ($findings->ownIndustry !== null) {
            $description .= ' Your own trade ('.$findings->ownIndustry.') is excluded, '
                .'so discovery looks for customers rather than competitors.';
        }

        return $description;
    }
}
