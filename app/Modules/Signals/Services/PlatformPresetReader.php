<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
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
 *
 * **Two deliberate exceptions to "the only place", both cross-tenant by design and neither a preset
 * read.** `Admin\Services\Platform\SignalLibrary` and `Admin\Services\Platform\PlatformRollup`
 * drop the scope to ask platform-wide questions *about* presets and the rows that descend from them
 * — how many teams run a copy, how far behind they are, what each preset found across every tenant
 * — which is ADR 0016's distinction: a platform question is not a tenant question with the scope
 * removed, and routing it through a reader built for "give me this preset" would mean teaching that
 * reader to aggregate. They sit behind the panel's `PlatformContext` and
 * `AdminAbility::ManageOperations`. The invariant this class actually protects is narrower than
 * "nobody else removes the scope": **nothing hands a caller a `SignalDefinition` it asked for by key
 * or by market unless that row belongs to the platform.**
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
     * Whether any preset holds this key — **including a withdrawn one**.
     *
     * Separate from {@see self::byKey()} because the two questions are genuinely different. A read
     * must not hand back a withdrawn preset: the library has stopped offering it and every screen
     * that lists one would start showing it again. But the **unique index does not soft-delete**,
     * so `unique(scope_id, key)` still holds the withdrawn row's key, and asking the ordinary read
     * whether a key is free gets "yes" followed by a 500 from the database.
     *
     * The alternative — force-deleting a withdrawn preset — is worse: clones keep `preset_key` and
     * promoted submissions keep `preset_id`, so a hard delete would orphan both and lose the record
     * of where a team's type came from.
     */
    public function keyIsTaken(string $key): bool
    {
        return SignalDefinition::query()
            ->withoutGlobalScope('team')
            ->withTrashed()
            ->whereNull('team_id')
            ->where('scope_id', SignalDefinition::PLATFORM_SCOPE)
            ->where('key', $key)
            ->exists();
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
