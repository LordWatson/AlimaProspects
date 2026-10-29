<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Requests;

use App\Modules\LeadsWorkspace\Actions\SyncLeadTagsAction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a lead's whole tag set, by name. Names rather than ids, because a tag is created on
 * first use — asking the client to create the tag and then attach it would be two round trips and
 * a chance to leave an orphan behind.
 */
final class SyncLeadTagsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tags' => ['present', 'array', 'max:'.SyncLeadTagsAction::MAX_PER_LEAD],
            'tags.*' => ['string', 'max:40'],
        ];
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        /** @var array<int, mixed> $tags */
        $tags = $this->validated('tags', []);

        return array_values(array_map(static fn (mixed $tag): string => (string) $tag, $tags));
    }
}
