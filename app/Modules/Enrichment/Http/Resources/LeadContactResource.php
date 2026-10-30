<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Http\Resources;

use App\Modules\Enrichment\Models\LeadContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeadContact
 */
final class LeadContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'title' => $this->title,
            'email' => $this->email,
            'phone' => $this->phone,
            'email_verification_status' => $this->email_verification_status?->value,
            'email_verification_label' => $this->email_verification_status?->label(),
            'email_confidence' => $this->email_confidence,
            'is_role_based' => $this->is_role_based,
            'is_personal' => $this->is_personal,
            'source' => $this->source,
            'source_url' => $this->source_url,
            'collected_at' => $this->collected_at?->toIso8601String(),
        ];
    }
}
