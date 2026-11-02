<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Support\SignalPreset;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Database\Seeder;

/**
 * Seeds the platform signal-definition presets from {@see SignalPresetCatalogue} (ADR 0018 §2).
 *
 * Platform reference data, like `TeamRolesSeeder`'s roles: it belongs to the product, not to a
 * tenant, and every install needs it. A team never edits one — they clone it (ADR 0018 §3), which
 * is why re-running this is safe even for a team that has tuned their copy.
 *
 * Idempotent: keyed on the platform scope plus the key, which is the table's own unique index.
 * Re-running updates a preset in place and so is how a corrected template reaches new installs;
 * existing clones are untouched by design, and S15 is what offers those teams the diff.
 */
class SignalPresetSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SignalPresetCatalogue::all() as $preset) {
            $this->write($preset);
        }
    }

    private function write(SignalPreset $preset): void
    {
        $row = $preset->toRow();

        // Written through the model so the casts do the JSON encoding, but **without** the global
        // team scope: a preset has no team, and the scope would otherwise hide the row this is
        // trying to update when the seeder runs with a team active (a test, a tinker session).
        $definition = SignalDefinition::query()
            ->withoutGlobalScope('team')
            ->whereNull('team_id')
            ->where('scope_id', SignalDefinition::PLATFORM_SCOPE)
            ->where('key', $preset->key)
            ->first();

        if ($definition instanceof SignalDefinition) {
            $definition->isPlatformPreset = true;
            $definition->forceFill($row)->save();

            return;
        }

        $definition = new SignalDefinition;
        // Set before the save, because `BelongsToTeam` assigns the active team on create and this
        // is the flag that undoes it (see the model).
        $definition->isPlatformPreset = true;
        $definition->forceFill($row)->save();
    }
}
