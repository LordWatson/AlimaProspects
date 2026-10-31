<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Onboarding\Enums\OnboardingStep;
use App\Modules\Onboarding\Models\OnboardingProgress;

/**
 * Where each tenant got to in setup. Read-only, and the most useful read-only table in the
 * panel: a team stuck on the same step for a fortnight is a churn signal that no revenue
 * report sees until it is too late to do anything about it.
 */
final class OnboardingProgressResource extends AdminResource
{
    public function model(): string
    {
        return OnboardingProgress::class;
    }

    public function key(): string
    {
        return 'onboarding-progress';
    }

    public function label(): string
    {
        return 'Onboarding progress';
    }

    public function singular(): string
    {
        return 'Onboarding progress';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Tenants;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('team.name', 'Team'),
            ResourceColumn::text('current_step', 'Current step'),
            ResourceColumn::date('created_at', 'Started'),
            ResourceColumn::date('updated_at', 'Last moved'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('completed_steps', 'Completed steps'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('current_step', 'Step', OnboardingStep::class),
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
            'updated_at',
        ];
    }
}
