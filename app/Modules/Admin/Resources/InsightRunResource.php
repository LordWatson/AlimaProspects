<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\AiInsights\Models\InsightRun;

/**
 * One row per team per day's insight generation, with what it cost. Read-only.
 */
final class InsightRunResource extends AdminResource
{
    public function model(): string
    {
        return InsightRun::class;
    }

    public function key(): string
    {
        return 'insight-runs';
    }

    public function label(): string
    {
        return 'Insight runs';
    }

    public function singular(): string
    {
        return 'Insight run';
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
            ResourceColumn::text('local_date', 'Local date'),
            ResourceColumn::count('insights_created', 'Insights'),
            ResourceColumn::text('ai_called', 'AI called'),
            ResourceColumn::count('cost_micros', 'Cost (micros)'),
            ResourceColumn::date('generated_at', 'Generated'),
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
            'generated_at',
            'cost_micros',
        ];
    }
}
