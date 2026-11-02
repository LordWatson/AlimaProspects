<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Observability;

use App\Modules\Core\Enums\Currency;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * The **AI usage** Pulse card (Phase 20, ADR 0017) — live AI spend, failures and latency per
 * prompt, over the dashboard's selected period.
 *
 * One of the three cards `mvp.md` Phase 20 asks for. The other two, queues and slow queries, are
 * the framework's own; this one has to be ours because nothing in Pulse knows what a prompt key or
 * a cost micro is.
 *
 * It is only a **renderer**: {@see AiUsageSummary} does the reading and the arithmetic, which the
 * review round moved out of here because all three of its sums are the kind that go wrong quietly
 * and each wanted a test of its own.
 *
 * **It is not the billing figure, and the card says so on its face.** The authoritative per-team
 * cost is the `ai_requests` ledger, read by Admin's platform reporting behind
 * `AdminAbility::ReadPlatformReports`. This one samples, trims and rounds, and disappears entirely
 * with `PULSE_ENABLED=false` — which is exactly the right trade for a screen whose question is "is
 * something wrong right now", and exactly the wrong one for an invoice.
 *
 * `#[Lazy]`, like Pulse's own cards: the dashboard renders its placeholder first and this query
 * runs in a follow-up request, so one slow aggregate cannot hold up the whole page.
 */
#[Lazy]
final class AiUsageCard extends Card
{
    public function render(): Renderable
    {
        [$prompts, $time, $runAt] = $this->remember(
            fn (): Collection => app(AiUsageSummary::class)->forPeriod($this->periodAsInterval()),
        );

        return View::make('pulse.ai-usage', [
            'prompts' => $prompts,
            'currency' => Currency::tryFrom((string) config('ai-platform.cost.currency')),
            'time' => $time,
            'runAt' => $runAt,
            'cols' => $this->cols,
            'rows' => $this->rows,
            'class' => $this->class,
            'expand' => $this->expand,
        ]);
    }
}
