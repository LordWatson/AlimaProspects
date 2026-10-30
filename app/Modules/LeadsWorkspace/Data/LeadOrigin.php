<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Data;

use App\Modules\LeadsWorkspace\Contracts\LeadOriginProvider;
use Spatie\LaravelData\Data;

/**
 * Where a lead came from, when "a provider found it" isn't the whole story — the read-only origin
 * panel on the lead detail screen (S4).
 *
 * The shape is deliberately generic. The workspace shows a kind, a headline, a line of detail, a
 * link out to the source and a link in to wherever the origin lives, and knows nothing about what
 * produced any of it — which is what lets Signals fill it in (via {@see LeadOriginProvider})
 * without the workspace depending on Signals.
 */
final class LeadOrigin extends Data
{
    /**
     * @param  string  $kind  machine key for the origin type (e.g. `signal`) — the screen keys its
     *                        icon and its heading off this, never off the label
     * @param  string  $label  the origin's display name, passed through `t()` by the screen
     * @param  string|null  $headline  the one line that identifies *this* origin to a member
     * @param  string|null  $detail  a short qualifier, e.g. the kind of event
     * @param  string|null  $url  the external source, if it has one (rendered as a link)
     * @param  string|null  $sourceName  who published it
     * @param  string|null  $occurredAt  ISO-8601, UTC — the screen formats it in the team's timezone
     * @param  string|null  $href  an in-app path to the origin, when the member can open it
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $label,
        public readonly ?string $headline = null,
        public readonly ?string $detail = null,
        public readonly ?string $url = null,
        public readonly ?string $sourceName = null,
        public readonly ?string $occurredAt = null,
        public readonly ?string $href = null,
    ) {}
}
