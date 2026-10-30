<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Http\Resources;

use App\Modules\Outreach\Models\OutreachSuppression;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One do-not-contact entry.
 *
 * `value_preview` is the only identifying field sent, and the only one the table holds — there is
 * no column with the address in the clear, and the hash is never exposed (a client could otherwise
 * test any address it liked against the list, which is an enumeration oracle over exactly the
 * people entitled to be left alone).
 *
 * Labels are translation keys (EN/AR, ADR 0007).
 *
 * @mixin OutreachSuppression
 */
final class OutreachSuppressionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope->value,
            'scope_label' => $this->scope->label(),
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'value_preview' => $this->value_preview,
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'note' => $this->note,
            'suppressed_at' => $this->suppressed_at->toIso8601String(),
            'can_delete' => $request->user()?->can('delete', $this->resource) ?? false,
        ];
    }
}
