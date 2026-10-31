<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;

/**
 * How each lead graded against a profile, with the AI's reasoning. Read-only — a score is an output, and an edited one would make the explanation beside it a lie.
 */
final class LeadScoreResource extends AdminResource
{
    public function model(): string
    {
        return LeadScore::class;
    }

    public function key(): string
    {
        return 'lead-scores';
    }

    public function label(): string
    {
        return 'Lead scores';
    }

    public function singular(): string
    {
        return 'Lead score';
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
            ResourceColumn::count('score', 'Score'),
            ResourceColumn::text('grade', 'Grade'),
            ResourceColumn::count('deterministic_score', 'Deterministic'),
            ResourceColumn::count('ai_score', 'AI'),
            ResourceColumn::text('excluded', 'Excluded'),
            ResourceColumn::date('scored_at', 'Scored'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('explanation', 'Explanation'),
            ResourceColumn::text('strengths', 'Strengths'),
            ResourceColumn::text('concerns', 'Concerns'),
            ResourceColumn::count('cost_micros', 'Cost (micros)'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('grade', 'Grade', ScoreGrade::class),
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
            'score',
            'scored_at',
        ];
    }
}
