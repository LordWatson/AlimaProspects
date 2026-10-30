<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Actions;

use App\Models\User;
use App\Modules\AiInsights\Enums\InsightStatus;
use App\Modules\AiInsights\Models\Insight;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Clears an insight away (Phase 14).
 *
 * Dismissing is not deleting: the row stays, visible under the Dismissed tab, so a member who
 * cleared the wrong card can read it again — and so the generator's dedupe key can see that this
 * day's insight has already been offered and refused (see {@see StoreInsightsAction}). The
 * retention sweep is what eventually removes it.
 *
 * Idempotent: dismissing an already-dismissed insight keeps the first member and the first
 * timestamp, because the question "who cleared this, and when" has one answer.
 */
final readonly class DismissInsightAction
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Insight $insight, User $member): Insight
    {
        if ($insight->status === InsightStatus::Dismissed) {
            return $insight;
        }

        return DB::transaction(function () use ($insight, $member): Insight {
            $insight->forceFill([
                'status' => InsightStatus::Dismissed,
                'dismissed_by' => $member->getKey(),
                'dismissed_at' => now(),
            ])->save();

            // Ids and the type only: the audit trail says an insight was cleared, not what it said.
            $this->audit->log('insight.dismissed', $insight, [
                'type' => $insight->type->value,
            ], $member);

            return $insight;
        });
    }
}
