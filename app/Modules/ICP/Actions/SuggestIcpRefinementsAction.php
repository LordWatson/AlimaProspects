<?php

declare(strict_types=1);

namespace App\Modules\ICP\Actions;

use App\Models\User;
use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Exceptions\InvalidAiResponseException;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Contracts\IcpCalibrationSource;
use App\Modules\ICP\Data\CriterionCalibration;
use App\Modules\ICP\Data\IcpData;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Enums\IcpSuggestionAction;
use App\Modules\ICP\Enums\IcpSuggestionStatus;
use App\Modules\ICP\Exceptions\IcpException;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use App\Modules\ICP\Models\IcpSuggestion;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Onboarding\Support\SellerBrief;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Asks what would make an ideal customer profile sharper, from the business itself **and** from how
 * the profile has actually behaved: which criteria match and miss, what members marked good and
 * bad, and what the relevance gate has been refusing (docs/plans/ai-relevance-plan.md P4).
 *
 * **It proposes and never applies.** The profile grades every lead in the account and shapes what
 * discovery searches for, so a prompt with write access to it would be a prompt with write access
 * to the team's pipeline. Each idea is stored as a pending {@see IcpSuggestion}; a member accepts
 * it through {@see DecideIcpSuggestionAction}, which is an ordinary audited profile update, or
 * declines it. ADR 0020 settled this arrangement for the Signals builder and ADR 0022 for its
 * tuning advisor; this is the third instance of the same rule.
 *
 * Three guards on what the model returns, none of them the prompt:
 *
 * 1. **The save path's own rules.** Each suggestion is validated against {@see IcpData::rules()};
 *    one the editor would refuse is dropped.
 * 2. **It has to be about this profile.** A `remove` or `reweight` must name a criterion the
 *    profile really has, an `add` one it does not. A suggestion to change something that is not
 *    there is the model talking about a different profile.
 * 3. **A declined idea is never raised twice.** The fingerprint is unique per profile, so an idea
 *    a member has already seen — pending, accepted or declined — is skipped.
 *
 * The AI call is external work and runs outside the transaction (CLAUDE.md).
 */
final class SuggestIcpRefinementsAction
{
    public const string PROMPT_KEY = 'icp.refine_suggestions';

    private const int MAX_SUGGESTIONS = 6;

    public function __construct(
        private readonly AiClient $ai,
        private readonly TeamContext $teamContext,
        private readonly IcpCalibrationSource $calibration,
    ) {}

    /**
     * @return Collection<int, IcpSuggestion> the suggestions newly raised by this call
     *
     * @throws IcpException when the AI is unusable — no credit, or no schema-valid answer
     */
    public function handle(Icp $icp, User $actor): Collection
    {
        return $this->teamContext->run($icp->team, function () use ($icp, $actor): Collection {
            $icp->loadMissing('criteria');
            $teamId = (int) $icp->team_id;

            $calibration = $this->calibration->summarise($icp);
            $feedback = $this->calibration->feedback($icp);

            try {
                $response = $this->ai->structured(self::PROMPT_KEY, [
                    // The team's own description of itself, including text an accepted background
                    // check read off a web page: data, never instructions.
                    'business_profile' => UntrustedContent::from(
                        SellerBrief::for(BusinessProfile::query()->where('team_id', $teamId)->first()) ?? 'Not yet described.',
                    ),
                    'profile' => UntrustedContent::from($this->profileBlock($icp)),
                    'calibration' => $this->calibrationBlock($calibration->scoredLeads, $calibration->criteria),
                    // Company-level facts about leads (never a name) and the gate's own one-line
                    // reasons, which are model-written and can mention the company they are
                    // about: wrapped, and sent nowhere but this prompt.
                    'feedback' => UntrustedContent::from($this->feedbackBlock($feedback)),
                    'criterion_types' => implode(', ', CriterionType::values()),
                ]);
            } catch (AiCreditExceededException|InvalidAiResponseException) {
                throw IcpException::suggestionsUnavailable();
            }

            $existing = IcpSuggestion::query()->where('icp_id', $icp->getKey())->pluck('fingerprint')->flip();
            $rows = [];

            foreach (array_slice((array) $response->get('suggestions', []), 0, self::MAX_SUGGESTIONS) as $raw) {
                $row = $this->usable($icp, is_array($raw) ? $raw : []);

                if ($row === null || isset($existing[$row['fingerprint']]) || isset($rows[$row['fingerprint']])) {
                    continue;
                }

                $rows[$row['fingerprint']] = $row;
            }

            /** @var Collection<int, IcpSuggestion> $created */
            $created = new Collection;

            foreach ($rows as $row) {
                // One short transaction each: a second request asking at the same moment may have
                // stored the same idea between our read and this insert. The unique index is the
                // backstop, and losing that race means the idea is already there — not an error,
                // and not a reason to throw away the other suggestions this call paid for.
                try {
                    $created->push(DB::transaction(fn (): IcpSuggestion => IcpSuggestion::query()->create([
                        ...$row,
                        'team_id' => $teamId,
                        'icp_id' => (int) $icp->getKey(),
                        'status' => IcpSuggestionStatus::Pending,
                        'prompt_version' => $response->promptVersion,
                    ])));
                } catch (UniqueConstraintViolationException) {
                    continue;
                }
            }

            // Counts and costs, never the rationale's prose or the team's profile text.
            Log::info('Suggested refinements for an ideal customer profile.', [
                'team_id' => $teamId,
                'user_id' => $actor->getKey(),
                'icp_id' => $icp->getKey(),
                'offered' => count((array) $response->get('suggestions', [])),
                'raised' => $created->count(),
                'cost_micros' => $response->costMicros,
                'prompt_version' => $response->promptVersion,
            ]);

            return $created;
        });
    }

    /**
     * One suggestion as storable attributes, or null when it is not something this profile could
     * act on.
     *
     * @param  array<array-key, mixed>  $raw
     * @return array{action: IcpSuggestionAction, type: CriterionType, value: string, weight: ?int, rationale: string, fingerprint: string}|null
     */
    private function usable(Icp $icp, array $raw): ?array
    {
        $action = IcpSuggestionAction::tryFrom((string) ($raw['action'] ?? ''));
        $rationale = Str::limit(Str::squish((string) ($raw['rationale'] ?? '')), 500, '');

        $validator = Validator::make($raw, [
            'type' => IcpData::rules()['criteria.*.type'],
            'value' => IcpData::rules()['criteria.*.value'],
            'weight' => IcpData::rules()['criteria.*.weight'],
        ]);

        if ($action === null || $rationale === '' || $validator->fails()) {
            return null;
        }

        $type = CriterionType::from((string) $raw['type']);
        $value = trim((string) $raw['value']);
        $weight = isset($raw['weight']) ? (int) $raw['weight'] : null;

        $present = $icp->criteria->contains(
            static fn (IcpCriterion $criterion): bool => $criterion->type === $type
                && Str::lower(trim($criterion->value)) === Str::lower($value),
        );

        // An addition must be new; a removal or a re-weighting must be of something that is there.
        if ($present === ($action === IcpSuggestionAction::Add)) {
            return null;
        }

        // An exclusion has no weight to change, and a re-weighting needs a weight to move to.
        if ($action === IcpSuggestionAction::Reweight && ($type->isExclusion() || $weight === null)) {
            return null;
        }

        return [
            'action' => $action,
            'type' => $type,
            'value' => $value,
            'weight' => match (true) {
                $action === IcpSuggestionAction::Remove, $type->isExclusion() => null,
                default => $weight ?? 50,
            },
            'rationale' => $rationale,
            'fingerprint' => IcpSuggestion::fingerprintFor($action, $type, $value),
        ];
    }

    private function profileBlock(Icp $icp): string
    {
        $lines = ['Name: '.Str::limit($icp->name, 120, '')];

        foreach ($icp->criteria as $criterion) {
            $lines[] = sprintf(
                '- %s: %s (%s)',
                $criterion->type->value,
                Str::limit($criterion->value, 200, ''),
                $criterion->type->isExclusion() ? 'exclusion' : 'weight '.($criterion->weight ?? 50),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<CriterionCalibration>  $criteria
     */
    private function calibrationBlock(int $scored, array $criteria): string
    {
        if ($scored === 0 || $criteria === []) {
            return 'No leads have been graded against this profile yet.';
        }

        $lines = [sprintf('%d leads graded. Per criterion, how often a lead matched it, clearly did not, or had no data:', $scored)];

        foreach (array_slice($criteria, 0, 20) as $row) {
            $lines[] = sprintf(
                '- %s "%s" (weight %s): matched %d, did not match %d, unconfirmed %d, no data %d',
                $row->type,
                Str::limit($row->value, 120, ''),
                $row->weight ?? 'none',
                $row->matched,
                $row->notMatched,
                $row->weak,
                $row->unknown,
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{good: list<string>, bad: list<string>, rejected: list<string>}  $feedback
     */
    private function feedbackBlock(array $feedback): string
    {
        $section = static fn (string $title, array $lines): string => $title.":\n"
            .($lines === [] ? '- none yet' : implode("\n", array_map(static fn (string $line): string => '- '.$line, $lines)));

        return implode("\n\n", [
            $section('Leads a member marked as a GOOD fit', $feedback['good']),
            $section('Leads a member marked as a BAD fit', $feedback['bad']),
            $section('Companies recent searches found and left out, with the reason', $feedback['rejected']),
        ]);
    }
}
