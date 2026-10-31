<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceField;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Core\Enums\Market;
use App\Modules\Onboarding\Enums\CompanySize;
use App\Modules\Onboarding\Models\BusinessProfile;

/**
 * What a tenant told us about itself in onboarding, and what every AI prompt in the
 * product is grounded in. Editable because a sales call often produces a better answer
 * than the form did — and because a wrong value here shows up as wrong leads later, which
 * is the hardest kind of support ticket to diagnose from the outside.
 */
final class BusinessProfileResource extends AdminResource
{
    public function model(): string
    {
        return BusinessProfile::class;
    }

    public function key(): string
    {
        return 'business-profiles';
    }

    public function label(): string
    {
        return 'Business profiles';
    }

    public function singular(): string
    {
        return 'Business profile';
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
            ResourceColumn::text('company_name', 'Company'),
            ResourceColumn::text('website', 'Website'),
            ResourceColumn::text('industry', 'Industry'),
            ResourceColumn::text('company_size', 'Size'),
            ResourceColumn::text('primary_market', 'Market'),
            ResourceColumn::date('updated_at', 'Updated'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('value_proposition', 'Value proposition'),
            ResourceColumn::text('products_services', 'Products and services'),
            ResourceColumn::money('pricing_min', 'Pricing from', 'pricing_currency'),
            ResourceColumn::money('pricing_max', 'Pricing to', 'pricing_currency'),
        ];
    }

    /**
     * @return list<ResourceField>
     */
    public function fields(): array
    {
        return [
            ResourceField::text('company_name', 'Company', ['required', 'string', 'max:255']),
            ResourceField::text('website', 'Website', ['nullable', 'url', 'max:2048']),
            ResourceField::text('industry', 'Industry'),
            ResourceField::enum('company_size', 'Size', CompanySize::class),
            ResourceField::enum('primary_market', 'Primary market', Market::class),
            ResourceField::textarea('value_proposition', 'Value proposition'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('primary_market', 'Market', Market::class),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'company_name',
            'website',
            'industry',
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
            'company_name',
            'updated_at',
        ];
    }

    public function allowsUpdate(): bool
    {
        return true;
    }
}
