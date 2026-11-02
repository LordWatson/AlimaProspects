<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Resources;

use App\Modules\Signals\Data\PreviewedItem;
use App\Modules\Signals\Data\SignalPreviewResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a preview found, with the verdict on every article (S9).
 *
 * The counts and the **diagnosis** are sent rather than left for a client to compute, for the same
 * reason the feed's labels are server-driven: "no news at all", "news but no events" and "events
 * your thresholds rejected" are three different problems with three different fixes, and a client
 * re-deriving which one it is would be a second implementation of the only judgement this screen
 * makes.
 *
 * `verdict_advice` is the sentence beside each chip. It comes from the enum, so the API and the
 * screen cannot say different things about the same verdict.
 *
 * @mixin SignalPreviewResult
 */
final class SignalPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SignalPreviewResult $result */
        $result = $this->resource;

        return [
            'queries' => $result->queries,
            'queries_requested' => $result->queriesRequested,
            'was_trimmed' => $result->wasTrimmed(),
            'result_count' => $result->resultCount(),
            'event_count' => $result->eventCount(),
            'kept_count' => $result->keptCount(),
            'cost_micros' => $result->costMicros,
            'classified' => $result->classified,
            'diagnosis' => $result->diagnosis(),
            'items' => array_map(static fn (PreviewedItem $item): array => [
                'headline' => $item->headline,
                'url' => $item->url,
                'source' => $item->source,
                'published_at' => $item->publishedAt?->toIso8601String(),
                'verdict' => $item->verdict->value,
                'verdict_label' => $item->verdict->label(),
                'verdict_advice' => $item->verdict->advice(),
                'found_an_event' => $item->verdict->foundAnEvent(),
                'confidence' => $item->confidence,
                'relevance' => $item->relevance,
                'summary' => $item->summary,
                'subject_company_name' => $item->subjectCompanyName,
                'subject_person_name' => $item->subjectPersonName,
                'location' => $item->location,
                'query' => $item->query,
            ], $result->items),
        ];
    }
}
