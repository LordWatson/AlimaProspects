<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceField;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\AdminAbility;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\AiPlatform\Enums\AiTier;
use App\Modules\AiPlatform\Models\AiPromptTemplate;
use Illuminate\Database\Eloquent\Model;

/**
 * The prompt registry — platform-wide, with no tenant behind it. The operational knobs are
 * editable (`is_active`, token ceiling, temperature, description); **the prompt text is
 * not**. A prompt is versioned code that ships with a release and is quoted in
 * `docs/ai-prompts.md`; editing the body in a browser would make the ledger's
 * `prompt_version` a lie about what was actually sent.
 */
final class AiPromptTemplateResource extends AdminResource
{
    public function model(): string
    {
        return AiPromptTemplate::class;
    }

    public function key(): string
    {
        return 'ai-prompt-templates';
    }

    public function label(): string
    {
        return 'AI prompts';
    }

    public function singular(): string
    {
        return 'AI prompt';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Intelligence;
    }

    /**
     * **Not `tenant.write`.** These rows are ours, not a tenant's, and an edit reaches every
     * customer's next AI call — so it is a release-shaped change behind a release-shaped ability
     * (`super_admin` only), not a support task.
     */
    public function ability(): AdminAbility
    {
        return AdminAbility::ManagePlatformData;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('key', 'Key'),
            ResourceColumn::count('version', 'Version'),
            ResourceColumn::text('tier', 'Tier'),
            ResourceColumn::count('max_tokens', 'Max tokens'),
            ResourceColumn::text('is_active', 'Active'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('description', 'Description'),
            ResourceColumn::text('system', 'System prompt'),
            ResourceColumn::text('body', 'Body'),
        ];
    }

    /**
     * @return list<ResourceField>
     */
    public function fields(): array
    {
        return [
            ResourceField::boolean('is_active', 'Active'),
            ResourceField::integer('max_tokens', 'Max tokens', ['required', 'integer', 'min:1', 'max:200000']),
            ResourceField::text('description', 'Description'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::boolean('is_active', 'Active'),
            ResourceFilter::enum('tier', 'Tier', AiTier::class),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'key',
            'description',
        ];
    }

    /**
     * @return list<string>
     */
    public function sortable(): array
    {
        return [
            'id',
            'key',
            'version',
        ];
    }

    public function allowsUpdate(): bool
    {
        return true;
    }

    public function title(Model $record): string
    {
        return (string) $record->getAttribute('key');
    }
}
