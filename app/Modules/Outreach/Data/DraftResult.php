<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Data;

use App\Modules\Outreach\Actions\DraftMessageAction;

/**
 * What {@see DraftMessageAction} carries from the model call into the write: the draft itself plus
 * the provenance stored alongside it (which prompt version wrote it, on which model, for what).
 *
 * A small type rather than a four-element array, so the transaction that writes the row can't
 * silently receive them in the wrong order.
 */
final readonly class DraftResult
{
    public function __construct(
        public MessageDraft $draft,
        public int $promptVersion,
        public string $model,
        public int $costMicros,
    ) {}
}
