<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Resources;

use App\Modules\Compliance\Models\RetentionPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A team's retention windows, and what the last sweep did.
 *
 * The last-sweep fields are not decoration: a retention promise nobody can see being kept is
 * indistinguishable from one that isn't, and "last run 4 March, removed 0" is how a team finds out
 * the schedule has stopped.
 *
 * @mixin RetentionPolicy
 */
final class RetentionPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Null for a transient policy: the team is on the defaults and has saved nothing.
            'id' => $this->exists ? $this->id : null,
            'is_enabled' => $this->is_enabled,
            'uncontacted_lead_days' => $this->uncontacted_lead_days,
            'contacted_lead_days' => $this->contacted_lead_days,
            'last_swept_at' => $this->last_swept_at?->toIso8601String(),
            'last_deleted_count' => $this->last_deleted_count,
            'last_anonymised_count' => $this->last_anonymised_count,
        ];
    }
}
