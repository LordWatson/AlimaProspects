<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Resources;

use App\Modules\Signals\Data\SignalPlaybookStep;
use App\Modules\Signals\Models\SignalPlaybook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One playbook, as the builder and the API read it (S14).
 *
 * Two things are sent rather than left for a client to derive, and both for the same reason the
 * watchlist resource sends `can_edit_entries`: they are the server's rules, and a screen that
 * re-derived them would be a second copy of each.
 *
 * - `is_rehearsing` — whether this rule acts. A client comparing `mode === 'dry_run'` would be a
 *   second place the mode's meaning lived, and `PlaybookMode::performs()` exists precisely so a
 *   third mode would be caught everywhere at once.
 * - `has_unknown_steps` — whether this build dropped a step it did not recognise. A rule built on a
 *   newer release and read by a rolled-back one does less than it says, and the screen has to be
 *   able to tell somebody that rather than leaving them to notice.
 *
 * `conditions` and `actions` go out in exactly the shape the save endpoint takes them, so the
 * builder round-trips a rule without translating it in either direction.
 *
 * @mixin SignalPlaybook
 */
final class SignalPlaybookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SignalPlaybook $playbook */
        $playbook = $this->resource;

        $steps = $playbook->steps();

        return [
            'id' => $playbook->id,
            'name' => $playbook->name,
            'is_active' => $playbook->is_active,
            'mode' => $playbook->mode->value,
            // Translation keys, not finished strings: the client passes them through `t()`.
            'mode_label' => $playbook->mode->label(),
            'mode_description' => $playbook->mode->description(),
            'is_rehearsing' => ! $playbook->mode->performs(),
            'stop_after_first_match' => $playbook->stop_after_first_match,
            'priority' => $playbook->priority,
            'conditions' => $playbook->conditionSet()->toStored(),
            // Read back through the DTO rather than echoed from the column, so a value stored by an
            // older release reaches the screen narrowed to the same shape a new one would write.
            'actions' => array_map(
                static fn (SignalPlaybookStep $step): array => $step->toStored(),
                $steps,
            ),
            'actions_count' => count($steps),
            'has_unknown_steps' => $playbook->hasUnknownSteps(),
            // How many times it has fired, when the caller asked for the count — the number that
            // tells somebody whether a rehearsal has had a chance to show them anything.
            'runs_count' => $this->whenCounted('runs'),
            'created_at' => $playbook->created_at?->toIso8601String(),
            'updated_at' => $playbook->updated_at?->toIso8601String(),
        ];
    }
}
