<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Signals\Data\SignalPlaybookStep;
use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Support\Playbook\PlaybookStepHandler;

/**
 * Resolves a {@see PlaybookStepHandler} for a {@see PlaybookActionKind} (S14).
 *
 * The same seam {@see SignalSourceRegistry} is, for the same reason: a new kind of step slots in by
 * registering a handler in `SignalsServiceProvider`, not by growing a `match` inside the executor.
 * A `match` there would be the place every new step had to be remembered, and the place a step
 * added to the enum but not to the executor would silently do nothing.
 *
 * {@see self::find()} returns null rather than throwing, and the executor treats a missing handler
 * the way {@see SignalPlaybookStep::fromStored()} treats an unknown kind
 * — the step is dropped and the rest of the rule is honoured. A rolled-back deploy should not fail
 * every signal a team's rules touch.
 */
final class PlaybookStepRegistry
{
    /**
     * @var array<string, PlaybookStepHandler>
     */
    private array $handlers = [];

    public function register(PlaybookStepHandler $handler): void
    {
        $this->handlers[$handler->kind()->value] = $handler;
    }

    public function find(PlaybookActionKind $kind): ?PlaybookStepHandler
    {
        return $this->handlers[$kind->value] ?? null;
    }

    public function has(PlaybookActionKind $kind): bool
    {
        return isset($this->handlers[$kind->value]);
    }

    /**
     * The kinds with a handler registered, in enum declaration order — what the builder offers.
     *
     * Read from the registry rather than from the enum's cases, so a kind declared and not yet
     * wired up is never put on the screen. The same separation `SignalSourceKind::isAvailable()`
     * draws one level up (ADR 0023 §3).
     *
     * @return list<PlaybookActionKind>
     */
    public function registered(): array
    {
        return array_values(array_filter(
            PlaybookActionKind::cases(),
            fn (PlaybookActionKind $kind): bool => $this->has($kind),
        ));
    }
}
