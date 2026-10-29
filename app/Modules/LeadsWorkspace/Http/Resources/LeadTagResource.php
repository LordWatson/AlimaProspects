<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Resources;

use App\Modules\LeadsWorkspace\Models\LeadTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A team's label. The name is the team's own word, never translated.
 *
 * @mixin LeadTag
 */
final class LeadTagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'leads_count' => $this->whenCounted('leads'),
        ];
    }
}
