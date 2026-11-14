<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Exceptions\AiPlatformException;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\Onboarding\Models\BusinessProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Judges each discovery candidate against the business that asked for it, **before** it becomes a
 * lead (docs/plans/ai-relevance-plan.md P2, ADR 0035).
 *
 * {@see LeadCandidateFilter} runs first and is free, but it can only refuse a company that names
 * the excluded trade in its own name: in the relevance baseline 86% of labelled competitors got
 * past it. "Studio Eight" is a design studio and nothing in its name says so. Telling that apart
 * takes a reading of what the company does, which is this class.
 *
 * Four rules, each load-bearing:
 *
 * 1. **It drops only what it is confident is wrong.** `not_fit` and `competitor` at or above the
 *    configured confidence are refused; `fit`, `unclear` and anything less sure are kept. A wrongly
 *    dropped customer is gone and nobody sees it go, while a wrongly kept one is graded and shown.
 * 2. **It degrades open, and never fails a run.** No profile to judge against, an exhausted AI
 *    allowance, a bad response, a spent call budget — every one of them lets the candidates through
 *    unjudged, exactly as they were before this class existed. Discovery is not an AI feature from
 *    the member's side ({@see DiscoveryQueryShaper} makes the same call).
 * 3. **It is bounded.** A fixed batch size and a fixed number of calls per run, on the cheap tier;
 *    candidates past the budget pass unjudged rather than costing more.
 * 4. **The reason is kept.** A kept candidate's reason becomes evidence on the lead, and a refused
 *    one's goes on the run, so a member can see both why a company is there and what was left out.
 */
final class CandidateRelevanceGate
{
    public const string PROMPT_KEY = 'lead_sourcing.judge_candidates';

    public const string EVIDENCE_SOURCE = 'relevance_gate';

    private const array REFUSALS = ['not_fit', 'competitor'];

    public function __construct(
        private readonly AiClient $ai,
        private readonly DiscoveryPayloadBuilder $payload,
    ) {}

    /**
     * @param  list<SourcedLead>  $leads
     * @return array{
     *     kept: list<SourcedLead>,
     *     rejected: list<array{name: string, verdict: string, reason: string}>,
     *     reasons: array<string, string>,
     *     judged: int,
     * } `reasons` is keyed by {@see SourcedLead::dedupeKey()} and holds why each `fit` was kept;
     *   `judged` is how many candidates a verdict actually came back for.
     */
    public function screen(DiscoveryQuery $query, array $leads): array
    {
        $result = ['kept' => $leads, 'rejected' => [], 'reasons' => [], 'judged' => 0];

        // The team in context, filtered explicitly: outside one this is null and nothing is judged.
        $profile = BusinessProfile::current();

        if ($leads === [] || ! $profile instanceof BusinessProfile || ! (bool) config('lead-sourcing.relevance_gate.enabled', true)) {
            return $result;
        }

        // By team as well as by id: a query naming another team's ICP gets no ICP, not theirs.
        $icp = $query->icpId === null
            ? null
            : Icp::query()->where('team_id', $profile->team_id)->with('criteria')->find($query->icpId);
        $floor = (int) config('lead-sourcing.relevance_gate.min_confidence', 70);
        $batches = array_slice(
            array_chunk($leads, max(1, (int) config('lead-sourcing.relevance_gate.batch_size', 25))),
            0,
            max(1, (int) config('lead-sourcing.relevance_gate.max_calls', 2)),
        );

        $refused = [];

        foreach ($batches as $batch) {
            try {
                $response = $this->ai->structured(self::PROMPT_KEY, [
                    'seller' => $this->payload->businessBlock($profile),
                    'icp' => $this->payload->icpBlock($icp),
                    'customer_description' => UntrustedContent::from($query->customerDescription ?? 'not stated'),
                    // Search results and provider records: data, never instructions.
                    'candidates' => UntrustedContent::from(implode("\n", array_map(
                        fn (SourcedLead $lead, int $i): string => ($i + 1).'. '.$this->describe($lead),
                        $batch,
                        array_keys($batch),
                    ))),
                ]);
            } catch (AiPlatformException $e) {
                // The class and code, never the message: it can quote back the request, which
                // holds the team's own description of itself. Whatever was judged so far stands.
                Log::channel('lead_sourcing')->warning('Relevance gate unavailable — candidates passed unjudged.', [
                    'icp_id' => $query->icpId,
                    'exception' => $e::class,
                    'error_code' => $e->errorCode(),
                ]);

                break;
            }

            foreach ((array) $response->get('verdicts', []) as $row) {
                $lead = is_array($row) && is_int($row['index'] ?? null) ? ($batch[$row['index'] - 1] ?? null) : null;

                // A verdict for a number that was never offered is the model talking, not judging.
                if (! $lead instanceof SourcedLead || ! is_string($row['verdict'] ?? null)) {
                    continue;
                }

                $result['judged']++;
                $reason = Str::limit(Str::squish((string) ($row['reason'] ?? '')), 300, '');

                if (in_array($row['verdict'], self::REFUSALS, true) && (int) ($row['confidence'] ?? 0) >= $floor) {
                    $refused[$lead->dedupeKey()] = true;
                    $result['rejected'][] = ['name' => $lead->companyName, 'verdict' => $row['verdict'], 'reason' => $reason];
                } elseif ($row['verdict'] === 'fit' && $reason !== '') {
                    $result['reasons'][$lead->dedupeKey()] = $reason;
                }
            }
        }

        $result['kept'] = array_values(array_filter(
            $leads,
            static fn (SourcedLead $lead): bool => ! isset($refused[$lead->dedupeKey()]),
        ));

        return $result;
    }

    /**
     * What is known about one candidate, on one line. Every provider knows something different — a
     * register has SIC codes, a web search has a snippet, a places listing has a category — so the
     * line carries whichever of them exist.
     */
    private function describe(SourcedLead $lead): string
    {
        $snippet = $lead->raw['snippet'] ?? null;
        $types = $lead->raw['types'] ?? null;
        $category = is_array($types) ? ($types[0] ?? null) : null;

        return Str::limit(Str::squish(implode(' | ', array_filter([
            $lead->companyName,
            $lead->domain(),
            $lead->industry !== null ? 'trade: '.$lead->industry : null,
            is_string($category) ? 'listed as: '.str_replace('_', ' ', $category) : null,
            $lead->sicCodes !== [] ? 'SIC: '.implode(', ', $lead->sicCodes) : null,
            implode(', ', array_filter([$lead->locality, $lead->region, $lead->country])) ?: null,
            $lead->employeeRange !== null ? 'staff: '.$lead->employeeRange : null,
            is_string($snippet) ? '"'.$snippet.'"' : null,
        ]))), 400, '');
    }
}
