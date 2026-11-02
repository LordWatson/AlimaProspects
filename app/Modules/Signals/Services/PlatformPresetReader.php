<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Enums\Market;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The **one** place in Signals that reads a platform preset (ADR 0018 §2).
 *
 * Presets live in `signal_definitions` with `team_id = null`, so the `BelongsToTeam` global scope
 * hides them from every team-scoped query — which is the behaviour we want everywhere else. Reading
 * them means removing that scope, and this class is the only thing allowed to do it.
 *
 * Why a class rather than an `orWhereNull('team_id')` in the global scope: that would make every
 * query in the module, forever, silently able to return rows belonging to nobody, and the next
 * model needing the same trick would copy it. Isolating the scope removal behind one door is how
 * `Admin\Support\PlatformContext` handles the same problem.
 *
 * Every method here re-asserts `team_id is null` rather than trusting the caller, so a preset
 * lookup can never hand back a tenant's row — not even when asked for one by id.
 */
final class PlatformPresetReader
{
    /**
     * Every active preset, optionally narrowed to the ones worth running in a market.
     *
     * Ordered by id, which is the order {@see SignalPresetCatalogue}
     * declares them in — so the library reads in the order it was designed in rather than
     * alphabetically.
     *
     * @return Collection<int, SignalDefinition>
     */
    public function active(?Market $market = null): Collection
    {
        $presets = $this->query()->where('is_active', true)->orderBy('id')->get();

        if ($market === null) {
            return $presets;
        }

        return $presets
            ->filter(static fn (SignalDefinition $preset): bool => $preset->appliesToMarket($market))
            ->values();
    }

    /**
     * One preset by its key, or null. Keys are unique within the platform scope
     * (`unique(scope_id, key)`), so there is at most one.
     */
    public function byKey(string $key): ?SignalDefinition
    {
        return $this->query()->where('key', $key)->first();
    }

    /**
     * The presets named by a list of keys, keyed by key. Unknown keys are simply absent — a caller
     * that asked for one that no longer exists gets a shorter list, not an exception, because a
     * form posted against last month's library is the member's bad luck rather than their mistake.
     *
     * @param  list<string>  $keys
     * @return Collection<string, SignalDefinition>
     */
    public function byKeys(array $keys): Collection
    {
        if ($keys === []) {
            /** @var Collection<string, SignalDefinition> $empty */
            $empty = new Collection;

            return $empty;
        }

        /** @var Collection<string, SignalDefinition> $presets */
        $presets = $this->query()
            ->where('is_active', true)
            ->whereIn('key', array_values(array_unique($keys)))
            ->orderBy('id')
            ->get()
            ->keyBy('key');

        return $presets;
    }

    /**
     * A query over the platform scope only.
     *
     * `withoutGlobalScope('team')` removes the tenancy constraint; `whereNull('team_id')` puts a
     * narrower one back in its place. The pair is the point — the scope is not dropped, it is
     * replaced with a stricter one.
     *
     * @return Builder<SignalDefinition>
     */
    private function query(): Builder
    {
        return SignalDefinition::query()
            ->withoutGlobalScope('team')
            ->whereNull('team_id')
            ->where('scope_id', SignalDefinition::PLATFORM_SCOPE);
    }
}
