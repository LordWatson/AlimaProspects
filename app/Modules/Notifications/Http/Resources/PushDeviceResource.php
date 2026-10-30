<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\PushDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A registered device.
 *
 * **The token is never here.** It is a bearer credential for sending *to* the device, the client
 * already has it, and a response that echoed it back would put it in another log. The model hides
 * it too; this is the second place that is true, because the field a Resource forgets is the field
 * that leaks.
 *
 * @mixin PushDevice
 */
final class PushDeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform->value,
            'platform_label' => $this->platform->label(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'registered_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
