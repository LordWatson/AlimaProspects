<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Scoring\Enums\FeedbackRating;
use App\Modules\Scoring\Models\ScoreFeedback;

/**
 * What members thought of a score. Read-only, and the first place to look when a tenant says the grades feel wrong.
 */
final class ScoreFeedbackResource extends AdminResource
{
    public function model(): string
    {
        return ScoreFeedback::class;
    }

    public function key(): string
    {
        return 'score-feedback';
    }

    public function label(): string
    {
        return 'Score feedback';
    }

    public function singular(): string
    {
        return 'Score feedback';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Prospecting;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('team.name', 'Team'),
            ResourceColumn::text('rating', 'Rating'),
            ResourceColumn::count('scored_value', 'Score at the time'),
            ResourceColumn::text('scored_grade', 'Grade at the time'),
            ResourceColumn::date('created_at', 'Given'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('comment', 'Comment'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('rating', 'Rating', FeedbackRating::class),
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
        ];
    }
}
