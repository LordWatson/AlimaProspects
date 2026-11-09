<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Resources;

use App\Modules\LeadsWorkspace\Models\LeadExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the team's recent exports (PL6).
 *
 * This is where the **receipt** is read: how many rows a file holds and how many companies were
 * left out because somebody there is on the do-not-contact list. A member who downloads 412 rows
 * from a list showing 415 leads needs that second figure, or they go looking for a bug in the
 * filter — and the list is also where a queued export lands, and where a member whose notification
 * email has expired comes back for the file.
 *
 * It carries **counts and state, never a cell of the file**.
 *
 * @mixin LeadExport
 */
final class LeadExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'preset' => $this->preset->value,
            'preset_label' => $this->preset->label(),
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'row_count' => $this->row_count,
            'suppressed_count' => $this->suppressed_count,
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            // Asked of the model rather than inferred from `state` on the client: the file can be
            // gone while the row still says `ready`, which is exactly when a download link must
            // not be offered.
            'downloadable' => $this->isDownloadable(),
        ];
    }
}
