<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\AdoptPresetChangesAction;
use App\Modules\Signals\Models\SignalDefinition;

/**
 * "Your preset has moved on" — what changed in the library since a team took their copy
 * (S15 task 5).
 *
 * **This class is the reason ADR 0018 §3 made a clone a copy rather than a reference.** A reference
 * would have meant a platform edit silently changing what thirty teams pay to run, overnight, with
 * no record of what changed; a copy means the team keeps running exactly what they chose and is
 * *offered* the change. The offer needs a diff, and the diff needs to be per field, because taking
 * somebody's improved templates is a different decision from taking their renaming of the type.
 *
 * Three rules about what is offered:
 *
 * - **Only the fields that describe the type**, not the fields that describe a team's use of it.
 *   A preset has no idea whether this team paused their copy, which sources they switched on or
 *   what they called it after they renamed it — so `is_active`, `source_config` and the team's own
 *   name are never in the diff. {@see self::FIELDS} is the whole list, and keeping it short is the
 *   point.
 * - **Only where the values actually differ.** A field the team has already brought into line, or
 *   never changed, is not an offer — it is noise in a panel whose whole job is to be scannable.
 * - **Nothing is applied here.** This is a read; {@see AdoptPresetChangesAction}
 *   is the write, and it takes the fields the member ticked. The same propose-never-apply rule
 *   S12's advisor follows (ADR 0022).
 *
 * The comparison is by `preset_version`: a clone records the version it was taken from, and a
 * promotion or an operator's edit bumps the preset's. Equal versions mean nothing to offer, whatever
 * the field values are — a team that has since edited their copy has not fallen behind, they have
 * made it theirs.
 */
final class PresetUpdateDiffer
{
    /**
     * The fields a clone may take from its preset, in the order the panel shows them.
     *
     * Deliberately short. Everything here is a statement about *what this kind of event is*;
     * nothing here is a statement about how one team runs it.
     *
     * @var list<string>
     */
    public const array FIELDS = [
        'name',
        'description',
        'query_templates',
        'query_templates_i18n',
        'match_keywords',
        'classification_rules',
        'thresholds',
        'suggested_approach_hint',
        'category',
        'icon',
        'markets',
    ];

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly PlatformPresetReader $presets,
    ) {}

    /**
     * Every clone of this team's that is behind its preset, keyed by definition id.
     *
     * @return array<int, array{preset_key: string, preset_version: int, from_version: int|null, changes: list<array<string, mixed>>}>
     */
    public function forTeam(Team $team): array
    {
        return $this->teamContext->run($team, function () use ($team): array {
            /** @var list<SignalDefinition> $clones */
            $clones = SignalDefinition::query()
                ->where('team_id', $team->getKey())
                ->whereNotNull('preset_key')
                ->get()
                ->all();

            if ($clones === []) {
                return [];
            }

            $offers = [];

            foreach ($clones as $clone) {
                $preset = $clone->preset_key === null ? null : $this->presets->byKey($clone->preset_key);

                if (! $preset instanceof SignalDefinition || ! $this->isBehind($clone, $preset)) {
                    continue;
                }

                $changes = $this->changes($clone, $preset);

                if ($changes === []) {
                    // Behind by version but identical in every field a clone may take — a preset
                    // whose only change was one of the fields this diff deliberately ignores.
                    // Offering "nothing has changed, would you like it?" is worse than silence.
                    continue;
                }

                $offers[(int) $clone->getKey()] = [
                    'preset_key' => $clone->preset_key ?? '',
                    'preset_version' => $preset->version,
                    'from_version' => $clone->preset_version,
                    'changes' => $changes,
                ];
            }

            return $offers;
        });
    }

    /**
     * The diff for one clone, or an empty list when there is nothing to offer.
     *
     * @return list<array<string, mixed>>
     */
    public function changes(SignalDefinition $clone, SignalDefinition $preset): array
    {
        $changes = [];

        foreach (self::FIELDS as $field) {
            $mine = $clone->getAttribute($field);
            $theirs = $preset->getAttribute($field);

            if ($this->same($mine, $theirs)) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                // Both sides, because a member deciding whether to take a change needs to see what
                // they would be giving up — an offer that showed only the new value would be
                // asking them to agree to something they cannot read.
                'mine' => $this->readable($mine),
                'theirs' => $this->readable($theirs),
            ];
        }

        return $changes;
    }

    /**
     * Whether this clone is behind the preset it came from.
     *
     * A clone with **no** recorded version (one taken before S7's column, or by a path that did not
     * set it) is treated as behind, so an old clone is offered the current library rather than
     * being stranded for ever by a null.
     */
    public function isBehind(SignalDefinition $clone, SignalDefinition $preset): bool
    {
        return $clone->preset_version === null || $clone->preset_version < $preset->version;
    }

    /**
     * Value comparison that does not care about key order inside the json columns.
     *
     * `thresholds` and `classification_rules` are associative and written by three different code
     * paths; comparing them with `===` would report a change every time somebody's array happened
     * to be built in a different order, and a panel that cries wolf is a panel nobody reads.
     */
    private function same(mixed $mine, mixed $theirs): bool
    {
        // **A null and an empty array are the same absence**, and three of the diffable columns
        // arrive as either depending on which code path last wrote them — a preset built by the
        // catalogue has `match_keywords => []` where one built by the composer has null. Comparing
        // them with `===` offered a change whose two sides were both blank, which is the panel
        // crying wolf about nothing at all.
        if ($this->absent($mine) && $this->absent($theirs)) {
            return true;
        }

        if (is_array($mine) && is_array($theirs)) {
            $this->sortDeep($mine);
            $this->sortDeep($theirs);

            return $mine == $theirs;
        }

        return $mine === $theirs;
    }

    /** Nothing there: a null, or an array with nothing in it. */
    private function absent(mixed $value): bool
    {
        return $value === null || $value === [];
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function sortDeep(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortDeep($item);
            }
        }

        unset($item);

        // By key where the array is associative, by value where it is a list: a template list's
        // order is meaningful to nobody, and a threshold map's key order is meaningful to nothing.
        if (array_is_list($value)) {
            sort($value);

            return;
        }

        ksort($value);
    }

    /**
     * A value the screen can render without knowing the column's shape.
     *
     * Lists and maps come back as lists of lines rather than as nested structures, because the diff
     * is read as text and a client that had to understand `classification_rules`' shape would be a
     * second place that shape lived.
     *
     * @return list<string>
     */
    private function readable(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_bool($value)) {
            return [$value ? 'yes' : 'no'];
        }

        if (is_scalar($value)) {
            return [(string) $value];
        }

        if (! is_array($value)) {
            return [];
        }

        $lines = [];
        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            // One level of nesting flattened to a comma list, which is as deep as any of the
            // diffable columns goes: `classification_rules` is `{includes: [], excludes: []}` and
            // `query_templates_i18n` is `{ar: []}`. Anything deeper would be a column this diff
            // should not have been offering in the first place.
            $rendered = is_array($item)
                ? implode(', ', array_map(
                    // Already narrowed by the `array_filter` beside it; anything that is not a
                    // scalar is dropped rather than rendered as an empty entry.
                    static fn (bool|float|int|string $part): string => (string) $part,
                    array_values(array_filter($item, 'is_scalar')),
                ))
                : $item;

            $text = is_scalar($rendered) ? (string) $rendered : '';

            if ($text === '') {
                continue;
            }

            $lines[] = $isList ? $text : $key.': '.$text;
        }

        return $lines;
    }
}
