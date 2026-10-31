<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceField;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Enums\LeadStatus;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Database\Eloquent\Model;

/**
 * The biggest table in the product, and the one cross-tenant index with no `team_id` in
 * its `where` clause — which makes it both the performance risk and the privacy risk this
 * phase's risk note is about. Every read of it leaves an `admin.resource.viewed` entry.
 *
 * A handful of company facts are editable, because correcting a mangled company name for a
 * customer on the phone is the single commonest support fix. **Delete is not**: a lead has
 * contacts, scores, notes, tasks, activities, messages and attempts hanging off it, and
 * the workspace's own bulk delete is what knows the order to take them down in.
 */
final class LeadResource extends AdminResource
{
    public function model(): string
    {
        return Lead::class;
    }

    public function key(): string
    {
        return 'leads';
    }

    public function label(): string
    {
        return 'Leads';
    }

    public function singular(): string
    {
        return 'Lead';
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
            ResourceColumn::text('company_name', 'Company'),
            ResourceColumn::text('team.name', 'Team'),
            ResourceColumn::text('source', 'Source'),
            ResourceColumn::text('status', 'Status'),
            ResourceColumn::text('enrichment_status', 'Enrichment'),
            ResourceColumn::text('country', 'Country'),
            ResourceColumn::date('collected_at', 'Collected'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('website', 'Website'),
            ResourceColumn::text('company_number', 'Company number'),
            ResourceColumn::text('industry', 'Industry'),
            ResourceColumn::text('locality', 'Locality'),
            ResourceColumn::text('region', 'Region'),
            ResourceColumn::text('postal_code', 'Postcode'),
            ResourceColumn::text('has_personal_data', 'Holds personal data'),
            ResourceColumn::date('anonymised_at', 'Anonymised'),
            ResourceColumn::count('enrichment_cost_micros', 'Enrichment cost (micros)'),
            ResourceColumn::text('dedupe_key', 'Dedupe key'),
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
            ResourceField::text('locality', 'Locality'),
            ResourceField::text('region', 'Region'),
            ResourceField::text('country', 'Country', ['nullable', 'string', 'max:64']),
            ResourceField::enum('status', 'Status', LeadStatus::class, ['required']),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('status', 'Status', LeadStatus::class),
            ResourceFilter::enum('source', 'Source', LeadSource::class),
            ResourceFilter::enum('enrichment_status', 'Enrichment', EnrichmentStatus::class),
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
            'company_number',
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
            'collected_at',
            'created_at',
        ];
    }

    public function allowsUpdate(): bool
    {
        return true;
    }

    public function title(Model $record): string
    {
        return (string) $record->getAttribute('company_name');
    }
}
