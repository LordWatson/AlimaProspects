<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Services;

use App\Modules\AiPlatform\Enums\AiRequestStatus;
use App\Modules\AiPlatform\Enums\AiTier;
use App\Modules\AiPlatform\Models\AiPromptTemplate;
use App\Modules\AiPlatform\Models\AiRequest;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Writes the per-call `ai_requests` ledger row and answers "how much has this team spent this
 * period?" for the credit guard and Reporting. Team-scoping and `team_id` assignment come from
 * {@see BelongsToTeam} via {@see TeamContext}; the row holds IDs, token
 * counts and cost only — never prompt content or personal data (CLAUDE.md logging rules).
 */
final class AiUsageLogger
{
    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * Record one AI call (success, failure or cache hit).
     */
    public function record(
        AiPromptTemplate $template,
        string $model,
        AiTier $tier,
        int $inputTokens,
        int $outputTokens,
        ?int $cacheReadInputTokens,
        int $costMicros,
        string $currency,
        int $latencyMs,
        bool $cacheHit,
        AiRequestStatus $status,
        ?string $errorCode = null,
    ): AiRequest {
        $request = new AiRequest([
            'user_id' => Auth::id(),
            'prompt_key' => $template->key,
            'prompt_version' => $template->version,
            'tier' => $tier,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cache_read_input_tokens' => $cacheReadInputTokens,
            'cost_micros' => $costMicros,
            'currency' => $currency,
            'latency_ms' => $latencyMs,
            'cache_hit' => $cacheHit,
            'status' => $status,
            'error_code' => $errorCode,
        ]);

        // team_id is auto-assigned from the active team; make it explicit if one is set so the row
        // is attributed even when the global scope would otherwise leave it null.
        if ($this->teamContext->id() !== null) {
            $request->team_id = $this->teamContext->id();
        }

        $request->save();

        // An AI call with no active team is unmetered and unattributed spend (e.g. a queued job that
        // forgot to set TeamContext). The credit guard can't bound it, so make it loud in the logs.
        if ($request->team_id === null && $costMicros > 0) {
            Log::channel('ai')->warning('AI request recorded without a team context (unattributed, unmetered spend).', [
                'prompt_key' => $request->prompt_key,
                'prompt_version' => $request->prompt_version,
                'cost_micros' => $costMicros,
            ]);
        }

        Log::channel('ai')->info('AI request', [
            'team_id' => $request->team_id,
            'prompt_key' => $request->prompt_key,
            'prompt_version' => $request->prompt_version,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_micros' => $costMicros,
            'cache_hit' => $cacheHit,
            'status' => $status->value,
            'error_code' => $errorCode,
        ]);

        return $request;
    }

    /**
     * Total AI spend (micro-units) for a team in the current billing period (calendar month, UTC).
     */
    public function usedMicros(Team $team): int
    {
        $start = Carbon::now('UTC')->startOfMonth();

        return $this->usedMicrosBetween($team, $start, $start->copy()->addMonth());
    }

    /**
     * Total AI spend (micro-units) for a team over an arbitrary window, `$from` inclusive and `$to`
     * exclusive. Billing reads this to meter a period that is not necessarily the current one — a
     * month that has just closed, for instance — without needing to know this table.
     *
     * The bounds must already be in **UTC**: they reach a `where` on a column written by workers in
     * UTC, so a local-time bound would shift the window by the offset and double-count the overlap
     * (CLAUDE.md §2).
     */
    public function usedMicrosBetween(Team $team, DateTimeInterface $from, DateTimeInterface $to): int
    {
        return (int) AiRequest::query()
            ->withoutGlobalScopes()
            ->where('team_id', $team->getKey())
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->sum('cost_micros');
    }
}
