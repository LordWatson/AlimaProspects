<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\AiInsights\Enums\InsightSeverity;
use App\Modules\AiInsights\Enums\InsightStatus;
use App\Modules\AiInsights\Enums\InsightType;
use App\Modules\AiInsights\Models\Insight;

/**
 * AI observations offered to a tenant. Read-only — an insight carries the prompt and model that produced it, and an edited one would misattribute somebody's words to a model.
 */
final class InsightResource extends AdminResource
{
    public function model(): string
    {
        return Insight::class;
    }

    public function key(): string
    {
        return 'insights';
    }

    public function label(): string
    {
        return 'Insights';
    }

    public function singular(): string
    {
        return 'Insight';
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
            ResourceColumn::text('type', 'Type'),
            ResourceColumn::text('status', 'Status'),
            ResourceColumn::text('severity', 'Severity'),
            ResourceColumn::text('headline', 'Headline'),
            ResourceColumn::date('generated_at', 'Generated'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('body', 'Body'),
            ResourceColumn::count('cost_micros', 'Cost (micros)'),
            ResourceColumn::text('model', 'Model'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('status', 'Status', InsightStatus::class),
            ResourceFilter::enum('type', 'Type', InsightType::class),
            ResourceFilter::enum('severity', 'Severity', InsightSeverity::class),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'headline',
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
        ];
    }
}
