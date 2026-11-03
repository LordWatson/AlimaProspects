<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Contracts\SignalOutreachDrafter;
use App\Modules\Signals\Data\SignalPlaybookData;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalPlaybook;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates one playbook (S14), transactionally and audited.
 *
 * Three things worth knowing:
 *
 * - **The ceiling is here, not in middleware.** A rule is evaluated against every signal and may
 *   convert and draft, so creating them is the lever a script could pull to multiply a team's bill —
 *   and ADR 0012's argument applies as it does everywhere else in this module: the API, a future
 *   import and anything without a request behind it meet the same limit.
 * - **The semantic check runs again here.** The form reports a stage move with no stage field by
 *   field; this refuses it for every other caller, because an invalid rule stored by the path that
 *   happened not to validate it would fail silently at three in the morning. The drafting
 *   vocabulary comes from {@see SignalOutreachDrafter}, so Signals never restates Outreach's enums.
 * - **Switching a rule from rehearsal to live is audited as its own thing.** It is the only edit on
 *   this screen that changes whether the product acts on somebody's pipeline, and an audit entry
 *   that said only "playbook updated" would make the one change anybody would ever come looking for
 *   indistinguishable from renaming it.
 */
final class SaveSignalPlaybookAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
        private readonly SignalOutreachDrafter $drafter,
    ) {}

    /**
     * @throws SignalsException when the team is at its ceiling, or a step cannot do anything
     */
    public function handle(
        Team $team,
        User $actor,
        SignalPlaybookData $data,
        ?SignalPlaybook $playbook = null,
    ): SignalPlaybook {
        $this->assertComplete($data);

        return $this->teamContext->run($team, fn (): SignalPlaybook => DB::transaction(function () use ($team, $actor, $data, $playbook): SignalPlaybook {
            $attributes = $data->toAttributes();

            if ($playbook instanceof SignalPlaybook) {
                $wasLive = $playbook->mode->performs();

                $playbook->forceFill($attributes)->save();

                $this->audit->log('signals.playbook_updated', $playbook, [
                    'name' => $playbook->name,
                    'is_active' => $playbook->is_active,
                    'steps' => count($data->steps),
                ], $actor);

                // The one edit that changes whether the product acts on its own. Its own entry, so
                // it is findable.
                if ($wasLive !== $playbook->mode->performs()) {
                    $this->audit->log('signals.playbook_mode_changed', $playbook, [
                        'mode' => $playbook->mode->value,
                    ], $actor);
                }

                return $playbook;
            }

            $this->assertRoomForAnother($team);

            /** @var SignalPlaybook $created */
            $created = SignalPlaybook::query()->create([
                ...$attributes,
                'team_id' => $team->getKey(),
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->log('signals.playbook_created', $created, [
                'name' => $created->name,
                'mode' => $created->mode->value,
                'steps' => count($data->steps),
            ], $actor);

            return $created;
        }));
    }

    /**
     * @throws SignalsException
     */
    private function assertComplete(SignalPlaybookData $data): void
    {
        $channels = array_map(
            static fn (array $channel): string => $channel['value'],
            $this->drafter->channels(),
        );

        $tones = array_map(
            static fn (array $tone): string => $tone['value'],
            $this->drafter->tones(),
        );

        $problems = $data->problems($channels, $tones);

        if ($problems !== []) {
            // The first one. A caller with no form gets a sentence to act on rather than a bag it
            // would have to render itself; the form path never reaches here, because its own
            // validator reported all of them at once against their fields.
            throw SignalsException::playbookIncomplete((string) reset($problems));
        }
    }

    /**
     * @throws SignalsException
     */
    private function assertRoomForAnother(Team $team): void
    {
        $max = max(1, (int) config('signals.playbooks.max_per_team', 10));

        if (SignalPlaybook::query()->count() >= $max) {
            throw SignalsException::tooManyPlaybooks($max);
        }
    }
}
