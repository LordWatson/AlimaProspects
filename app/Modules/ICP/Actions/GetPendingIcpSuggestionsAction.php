<?php

declare(strict_types=1);

namespace App\Modules\ICP\Actions;

use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Enums\IcpSuggestionStatus;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpSuggestion;

/**
 * The suggestions for one profile that nobody has answered yet, oldest first, shaped for the screen.
 */
final class GetPendingIcpSuggestionsAction
{
    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * @return list<array{id: int, action: string, type: string, type_label: string, value: string, weight: ?int, rationale: string}>
     */
    public function handle(Icp $icp): array
    {
        return array_values($this->teamContext->run((int) $icp->team_id, fn (): array => IcpSuggestion::query()
            ->where('team_id', $icp->team_id)
            ->where('icp_id', $icp->getKey())
            ->where('status', IcpSuggestionStatus::Pending)
            ->orderBy('id')
            ->get()
            ->map(static fn (IcpSuggestion $suggestion): array => [
                'id' => (int) $suggestion->getKey(),
                'action' => $suggestion->action->value,
                'type' => $suggestion->type->value,
                'type_label' => $suggestion->type->label(),
                'value' => $suggestion->value,
                'weight' => $suggestion->weight,
                'rationale' => $suggestion->rationale,
            ])
            ->all()));
    }
}
