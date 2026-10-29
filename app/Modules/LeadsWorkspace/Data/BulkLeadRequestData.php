<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Data;

use App\Modules\LeadsWorkspace\Actions\BulkUpdateLeadsAction;
use App\Modules\LeadsWorkspace\Enums\BulkLeadAction;
use App\Modules\LeadsWorkspace\Http\Requests\BulkLeadActionRequest;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * The validated input for a bulk action (from {@see BulkLeadActionRequest}), as
 * {@see BulkUpdateLeadsAction} takes it. Only the field the chosen action actually uses is carried;
 * the others stay null, so nothing can quietly apply a stage change while asking for a tag.
 */
final class BulkLeadRequestData extends Data
{
    /**
     * @param  list<int>  $leadIds
     * @param  list<string>  $tags
     */
    public function __construct(
        public readonly BulkLeadAction $action,
        public readonly array $leadIds,
        public readonly array $tags = [],
        public readonly ?int $userId = null,
        public readonly ?int $pipelineStageId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $action = BulkLeadAction::from((string) $validated['action']);

        return new self(
            action: $action,
            leadIds: array_values(array_unique(array_map(
                static fn (mixed $id): int => (int) $id,
                (array) ($validated['lead_ids'] ?? []),
            ))),
            tags: $action->requires() === 'tags' ? self::tags($validated['tags'] ?? []) : [],
            userId: $action->requires() === 'user_id' && isset($validated['user_id'])
                ? (int) $validated['user_id']
                : null,
            pipelineStageId: $action->requires() === 'pipeline_stage_id' && isset($validated['pipeline_stage_id'])
                ? (int) $validated['pipeline_stage_id']
                : null,
        );
    }

    /**
     * @return list<string>
     */
    private static function tags(mixed $tags): array
    {
        $names = [];

        foreach ((array) $tags as $tag) {
            if (! is_string($tag)) {
                continue;
            }

            $name = Str::limit(Str::squish($tag), 40, '');

            if ($name !== '' && ! in_array($name, $names, strict: true)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
