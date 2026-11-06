<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Platform;

use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Enums\SignalSubmissionStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalDefinitionSubmission;
use Illuminate\Support\Facades\DB;

/**
 * What the platform's signal library looks like from the operator's side (S15 task 4).
 *
 * **A reader, not a writer.** Every change to a preset or a submission goes through Signals' own
 * Actions (`SavePlatformPresetAction`, `PromoteSignalDefinitionAction`), which is
 * `docs/modules/admin.md`'s standing rule: the panel owns no business rule, and what a preset *is*
 * — a `signal_definitions` row with `team_id = null`, versioned, unique within the platform scope —
 * belongs to the module that invented it (ADR 0018 §2).
 *
 * What this class does own is the **questions an operator asks**, and they are not a tenant's
 * questions with the scope removed (ADR 0016's rule for platform reporting, applied to a curation
 * screen):
 *
 * - *How widely is this preset used, and how far behind are the copies?* A tenant never asks that;
 *   it decides which presets are worth maintaining.
 * - *How has this submitted type actually performed — for the team that wrote it?* Which is the only
 *   honest basis for promoting one.
 *
 * **The author is anonymised and the evidence is not.** A submission's review row carries the type's
 * own figures and not whose type it is, because an operator choosing between two submissions should
 * be choosing on the evidence. The team id exists on the row — somebody has to be told the answer —
 * and this reader simply does not hand it to the screen.
 *
 * Every read is explicitly cross-tenant (`withoutGlobalScopes`) and reachable only from inside the
 * panel's `PlatformContext`.
 */
final class SignalLibrary
{
    /**
     * Every preset, with how many teams run a copy and how many of those are behind.
     *
     * @return list<array<string, mixed>>
     */
    public function presets(): array
    {
        /** @var list<SignalDefinition> $presets */
        $presets = SignalDefinition::query()
            // **`withoutGlobalScope('team')`, not `withoutGlobalScopes()`** — the second form drops
            // the soft-delete scope as well, so a withdrawn preset came back into the list and
            // could be toggled and withdrawn again (review). Only the tenancy scope is in the way
            // here; the rest are doing their job.
            ->withoutGlobalScope('team')
            ->whereNull('team_id')
            ->orderBy('id')
            ->get()
            ->all();

        if ($presets === []) {
            return [];
        }

        $clones = $this->cloneCounts();

        return array_map(static function (SignalDefinition $preset) use ($clones): array {
            $counts = $clones[$preset->key] ?? ['clones' => 0, 'behind' => 0];

            return [
                'id' => (int) $preset->getKey(),
                'key' => $preset->key,
                'name' => $preset->name,
                'description' => $preset->description,
                'category' => $preset->category->value,
                'icon' => $preset->icon->value,
                'is_active' => $preset->is_active,
                'version' => $preset->version,
                'templates' => count($preset->query_templates),
                // **The templates themselves, not only how many** — the editor opened with an
                // empty Searches box because the row carried a count, so an operator editing a
                // preset's name was shown a form that had thrown its searches away (review). A
                // preset is public to every tenant by definition, so there is nothing here to
                // withhold from an operator.
                'query_templates' => $preset->query_templates,
                // The preset's **own** floors, not the effective ones: null means "use the
                // platform default", and resolving it here would turn every preset's blank into
                // an explicit number the moment an operator pressed Save.
                'min_confidence' => $preset->thresholds['min_confidence'] ?? null,
                'min_relevance' => $preset->thresholds['min_relevance'] ?? null,
                'markets' => $preset->markets,
                'source_keys' => $preset->source_keys,
                // **The two figures that decide whether a preset is worth maintaining**: how many
                // teams run a copy, and how many of those have not taken the latest change. A
                // preset with ninety clones all one version behind is a change nobody wanted.
                'clones' => $counts['clones'],
                'clones_behind' => $counts['behind'],
                'created_at' => $preset->created_at?->toIso8601String(),
                'updated_at' => $preset->updated_at?->toIso8601String(),
            ];
        }, $presets);
    }

    /**
     * Submissions waiting for an answer, with the type's own performance and **not** its author.
     *
     * @return list<array<string, mixed>>
     */
    public function queue(int $limit = 50): array
    {
        /** @var list<SignalDefinitionSubmission> $submissions */
        $submissions = SignalDefinitionSubmission::query()
            ->withoutGlobalScopes()
            ->where('status', SignalSubmissionStatus::Pending->value)
            // **The eager load needs the scope dropped too, and that is the part that is easy to
            // miss.** `withoutGlobalScopes()` on *this* query makes the submissions cross-tenant;
            // the relation is a second query, and it re-applies `BelongsToTeam` — so with any team
            // in context the operator's queue showed every row with a null definition: no name, no
            // templates, "no longer available", and a performance figure of zero. In production
            // `PlatformContext` drops the context so it works by luck of the caller, which is the
            // same ambient dependency `asPlatform` was written for and then removed in favour of
            // saying what we mean. `withTrashed` because a team may delete the type while the
            // offer waits — the queue's job is to say so.
            ->with(['definition' => fn ($query) => $query->withoutGlobalScope('team')->withTrashed()])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get()
            ->all();

        if ($submissions === []) {
            return [];
        }

        // Keyed by `(team, event_key)`, which is the correction the review found: a team's clone
        // usually carries the **preset's own key**, so aggregating by key alone showed an operator
        // every tenant's totals for `funding_round` as the evidence for one team's submission.
        $performance = $this->performanceFor(array_map(
            static fn (SignalDefinitionSubmission $submission): array => [
                (int) $submission->team_id,
                (string) ($submission->definition->key ?? ''),
            ],
            $submissions,
        ));

        return array_map(static function (SignalDefinitionSubmission $submission) use ($performance): array {
            $definition = $submission->definition;
            $key = $definition instanceof SignalDefinition ? $definition->key : '';
            $scoped = $submission->team_id.'|'.$key;

            return [
                'id' => (int) $submission->getKey(),
                // No `team_id`, and that is the decision rather than an omission — see the class
                // note. The row carries it; this does not hand it over.
                'name' => $definition?->name,
                'description' => $definition?->description,
                'key' => $key,
                'category' => $definition?->category->value,
                'templates' => $definition === null ? [] : $definition->query_templates,
                'markets' => $definition?->markets,
                'source_keys' => $definition?->source_keys,
                // The author's own covering note, as prose. There is nothing to translate about
                // somebody's own words, and it is the main thing an operator reads.
                'note' => $submission->note,
                // How the type has actually done for the team that wrote it. The only honest basis
                // for putting it in front of everybody else.
                'performance' => $performance[$scoped] ?? ['found' => 0, 'kept' => 0, 'converted' => 0],
                // Whether the type still exists. A team may delete it while the offer waits, and an
                // operator must not be left pressing promote on nothing.
                'is_available' => $definition !== null && ! $definition->trashed(),
                'created_at' => $submission->created_at?->toIso8601String(),
            ];
        }, $submissions);
    }

    /**
     * Teams running a copy of each preset, and how many are behind it.
     *
     * One grouped query rather than one per preset. The join is on `preset_key` and the comparison
     * on `preset_version` against the preset's `version`, which is the same pair the tenant-facing
     * offer uses — so an operator's "behind" and a team's "your preset has moved on" cannot
     * disagree.
     *
     * @return array<string, array{clones: int, behind: int}>
     */
    private function cloneCounts(): array
    {
        $rows = DB::table('signal_definitions as clones')
            ->join('signal_definitions as presets', 'presets.key', '=', 'clones.preset_key')
            ->whereNull('presets.team_id')
            ->whereNull('presets.deleted_at')
            ->whereNotNull('clones.team_id')
            ->whereNull('clones.deleted_at')
            ->groupBy('clones.preset_key')
            ->selectRaw(
                'clones.preset_key as preset_key, '
                .'count(*) as clones, '
                // A clone with **no** recorded version counts as behind, which is the same reading
                // `PresetUpdateDiffer::isBehind()` takes: a copy taken before the column existed is
                // offered the current library rather than stranded by a null.
                .'sum(case when clones.preset_version is null or clones.preset_version < presets.version then 1 else 0 end) as behind',
            )
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->preset_key] = [
                'clones' => (int) $row->clones,
                'behind' => (int) $row->behind,
            ];
        }

        return $counts;
    }

    /**
     * Found / kept / converted for **each submitting team's own** type, keyed `"{teamId}|{key}"`.
     *
     * The same funnel definitions the tenant-facing ROI uses — per cluster representative, "kept"
     * meaning not dismissed — because an operator comparing a submission against the library should
     * be reading the same numbers the team is.
     *
     * **Scoped per team, and the review found out why that is not optional.** A team's clone
     * usually carries the preset's own key, and a team's hand-built type carries whatever name they
     * chose — so `funding_round` is one `event_key` shared by dozens of tenants. Aggregating by key
     * alone put every tenant's totals under one submission and labelled them "how this type has
     * done for the team that wrote it", which is the single thing on this screen an operator is
     * meant to be deciding on. The group is `(team_id, event_key)` and the lookup key is both.
     *
     * Cross-tenant by design: the figures are read for several tenants in one query, under the
     * panel's `PlatformContext`, which is the only place that is allowed.
     *
     * @param  list<array{0: int, 1: string}>  $pairs  `[teamId, eventKey]`
     * @return array<string, array{found: int, kept: int, converted: int}>
     */
    private function performanceFor(array $pairs): array
    {
        $wanted = [];

        foreach ($pairs as [$teamId, $key]) {
            if ($key !== '' && $teamId > 0) {
                $wanted[$teamId.'|'.$key] = [$teamId, $key];
            }
        }

        if ($wanted === []) {
            return [];
        }

        $query = Signal::query()
            ->withoutGlobalScope('team')
            ->representatives()
            ->where(function ($outer) use ($wanted): void {
                foreach ($wanted as [$teamId, $key]) {
                    // A pair at a time rather than two `whereIn`s, which would have crossed the
                    // teams with the keys and counted one team's signals under another's key.
                    $outer->orWhere(fn ($inner) => $inner
                        ->where('signals.team_id', $teamId)
                        ->where('signals.event_key', $key));
                }
            })
            ->groupBy('signals.team_id', 'signals.event_key')
            ->selectRaw(
                'signals.team_id as team_id, '
                .'signals.event_key as event_key, '
                .'count(*) as found, '
                .'sum(case when signals.status != ? then 1 else 0 end) as kept, '
                .'sum(case when signals.status = ? then 1 else 0 end) as converted',
                [SignalStatus::Dismissed->value, SignalStatus::Converted->value],
            );

        $performance = [];

        foreach ($query->toBase()->get() as $row) {
            $performance[$row->team_id.'|'.(string) $row->event_key] = [
                'found' => (int) $row->found,
                'kept' => (int) $row->kept,
                'converted' => (int) $row->converted,
            ];
        }

        return $performance;
    }
}
