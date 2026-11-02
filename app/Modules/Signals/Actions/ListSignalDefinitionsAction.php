<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Models\SignalDefinition;
use Illuminate\Database\Eloquent\Collection;

/**
 * The team's own signal types, for the library screen and the watch editor (S7, ADR 0018).
 *
 * A team has a handful of types, not a page of them, so this returns them all rather than
 * paginating — the same call the watch list makes for the same reason.
 *
 * Each row carries `watches_count`, because that number is the answer to the two questions the
 * screen has to answer about a type: whether it is doing anything, and what deleting it would cost.
 * `signals_count` is the third, and is the only honest measure of whether a type earns its keep —
 * which is why it is here from the start rather than waiting for S12's reporting.
 */
final class ListSignalDefinitionsAction
{
    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * @return Collection<int, SignalDefinition>
     */
    public function handle(Team $team): Collection
    {
        return $this->teamContext->run($team, static fn (): Collection => SignalDefinition::query()
            // Explicit beside the global scope (ADR 0006). It matters more than usual on this
            // table: a missing scope here would return every *other* team's types **and** the
            // platform presets, which a team may not edit.
            ->where('team_id', $team->getKey())
            ->withCount(['watches', 'signals'])
            ->orderBy('id')
            ->get());
    }
}
