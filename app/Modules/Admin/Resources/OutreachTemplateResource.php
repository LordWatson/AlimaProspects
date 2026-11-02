<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceField;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Core\Enums\SupportedLocale;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\OutreachTone;
use App\Modules\Outreach\Models\OutreachTemplate;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's reusable message shells. Editable, because a template with a broken placeholder goes
 * out to every prospect until somebody notices — and creatable, because writing one *for* a
 * customer during onboarding is a real support task.
 *
 * `channel`, `tone` and `locale` are declared as fields as well as filters precisely so that create
 * works: the table requires all three, and the registry only permits a create where every required
 * column is a declared field.
 */
final class OutreachTemplateResource extends AdminResource
{
    public function model(): string
    {
        return OutreachTemplate::class;
    }

    public function key(): string
    {
        return 'outreach-templates';
    }

    public function label(): string
    {
        return 'Outreach templates';
    }

    public function singular(): string
    {
        return 'Outreach template';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Outreach;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('team.name', 'Team'),
            ResourceColumn::text('name', 'Name'),
            ResourceColumn::text('channel', 'Channel'),
            ResourceColumn::text('tone', 'Tone'),
            ResourceColumn::text('locale', 'Locale'),
            ResourceColumn::text('is_default', 'Default'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('subject', 'Subject'),
            ResourceColumn::text('body', 'Body'),
        ];
    }

    /**
     * @return list<ResourceField>
     */
    public function fields(): array
    {
        return [
            ResourceField::text('name', 'Name', ['required', 'string', 'max:80']),
            ResourceField::enum('channel', 'Channel', OutreachChannel::class, ['required']),
            ResourceField::enum('tone', 'Tone', OutreachTone::class, ['required']),
            ResourceField::enum('locale', 'Locale', SupportedLocale::class, ['required']),
            ResourceField::text('subject', 'Subject'),
            ResourceField::textarea('body', 'Body', ['required', 'string', 'max:20000']),
            ResourceField::boolean('is_default', 'Default for this channel'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('channel', 'Channel', OutreachChannel::class),
            ResourceFilter::enum('tone', 'Tone', OutreachTone::class),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'name',
            'subject',
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
            'name',
        ];
    }

    public function allowsCreate(): bool
    {
        return true;
    }

    public function allowsUpdate(): bool
    {
        return true;
    }

    public function title(Model $record): string
    {
        return (string) $record->getAttribute('name');
    }
}
