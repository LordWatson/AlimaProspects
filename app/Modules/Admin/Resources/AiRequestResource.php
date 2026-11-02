<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\AiPlatform\Enums\AiRequestStatus;
use App\Modules\AiPlatform\Enums\AiTier;
use App\Modules\AiPlatform\Models\AiRequest;

/**
 * The AI spend ledger — every call, its tokens, its latency and its cost. Read-only and
 * append-only: this is the table a team's allowance, its usage meter and (from Phase 19c)
 * its gross margin are all computed from, so a row edited here would change a number
 * somebody has already been billed on.
 */
final class AiRequestResource extends AdminResource
{
    public function model(): string
    {
        return AiRequest::class;
    }

    public function key(): string
    {
        return 'ai-requests';
    }

    public function label(): string
    {
        return 'AI requests';
    }

    public function singular(): string
    {
        return 'AI request';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Intelligence;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('team.name', 'Team'),
            ResourceColumn::text('prompt_key', 'Prompt'),
            ResourceColumn::text('model', 'Model'),
            ResourceColumn::text('status', 'Status'),
            ResourceColumn::count('input_tokens', 'In'),
            ResourceColumn::count('output_tokens', 'Out'),
            ResourceColumn::count('cost_micros', 'Cost (micros)'),
            ResourceColumn::count('latency_ms', 'Latency (ms)'),
            ResourceColumn::date('created_at', 'When'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('error_code', 'Error code'),
            ResourceColumn::count('cache_read_input_tokens', 'Cached in'),
            ResourceColumn::text('cache_hit', 'Cache hit'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('status', 'Status', AiRequestStatus::class),
            ResourceFilter::enum('tier', 'Tier', AiTier::class),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'prompt_key',
            'model',
        ];
    }

    /**
     * @return list<string>
     */
    public function with(): array
    {
        return [
            'team',
        ];
    }

    /**
     * @return list<string>
     */
    public function sortable(): array
    {
        return [
            'id',
            'created_at',
            'cost_micros',
            'latency_ms',
        ];
    }
}
