<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Enums\WatchlistEntityType;
use App\Modules\Signals\Enums\WatchlistSourceKind;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * The validated input for creating or updating a watchlist (S10).
 *
 * `rules()` sits beside the shape it validates so the web and API endpoints cannot drift on what a
 * watchlist may be — the arrangement `SignalWatchData` and `SignalDefinitionData` already use.
 *
 * Everything here is written by a customer and some of it reaches a **search provider**, so the
 * clamping is not decoration: a name is squished and truncated in characters, the list of names is
 * capped at the configured ceiling, and `source_kind` is an enum rather than a free string.
 */
final class SignalWatchlistData extends Data
{
    public const int MAX_NAME = 120;

    /**
     * @param  list<int>  $stages  Pipeline stages to mirror; empty means every stage.
     * @param  list<int>  $owners  Members whose leads to mirror; empty means everyone's.
     */
    public function __construct(
        public readonly string $name,
        public readonly WatchlistSourceKind $sourceKind,
        public readonly bool $isActive = true,
        public readonly array $stages = [],
        public readonly array $owners = [],
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $kind = WatchlistSourceKind::tryFrom((string) ($validated['source_kind'] ?? '')) ?? WatchlistSourceKind::Manual;

        return new self(
            name: Str::limit(Str::squish((string) ($validated['name'] ?? '')), self::MAX_NAME, '') ?: 'My accounts',
            // An un-offered kind falls back to `manual` rather than being rejected here: the rule
            // below refuses it, and this is the belt to its braces.
            sourceKind: $kind->isOffered() ? $kind : WatchlistSourceKind::Manual,
            isActive: array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
            stages: self::ids($validated['stages'] ?? null),
            owners: self::ids($validated['owners'] ?? null),
        );
    }

    /**
     * The attributes for the model, ready to create or fill with.
     *
     * `sync_settings` is null for a manual list rather than an empty structure: a list nothing syncs
     * has no sync settings, and storing `{"stages":[],"owners":[]}` on one would read as "mirror
     * everything" to anybody who later made it a pipeline list.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'source_kind' => $this->sourceKind,
            'is_active' => $this->isActive,
            'sync_settings' => $this->sourceKind->isSynced()
                ? ['stages' => $this->stages, 'owners' => $this->owners]
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.self::MAX_NAME],
            // Only the kinds a member may actually choose. `segment` exists on the enum and is
            // refused here, because a case with no implementation behind it is worse than no case.
            'source_kind' => [
                'required',
                'string',
                'in:'.implode(',', array_map(
                    static fn (WatchlistSourceKind $kind): string => $kind->value,
                    WatchlistSourceKind::offered(),
                )),
            ],
            'is_active' => ['boolean'],
            // Both are "which of my own things to mirror", so both are bounded by what a team
            // plausibly has rather than left open.
            'stages' => ['nullable', 'array', 'max:20'],
            'stages.*' => ['integer', 'min:1'],
            'owners' => ['nullable', 'array', 'max:50'],
            'owners.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * The rules for adding names to a list, which is a different shape from the list itself.
     *
     * @return array<string, mixed>
     */
    public static function entryRules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1', 'max:'.max(1, (int) config('signals.watchlist.max_entries', 250))],
            'entries.*.name' => ['required', 'string', 'max:'.SignalWatchlistEntry::MAX_NAME],
            'entries.*.entity_type' => ['nullable', 'string', 'in:'.implode(',', WatchlistEntityType::values())],
            'entries.*.aliases' => ['nullable', 'array', 'max:'.SignalWatchlistEntry::MAX_ALIASES],
            'entries.*.aliases.*' => ['string', 'max:'.SignalWatchlistEntry::MAX_NAME],
            'entries.*.domain' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * A bounded list of positive integer ids.
     *
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $entry) {
            if (! is_numeric($entry)) {
                continue;
            }

            $id = (int) $entry;

            if ($id > 0 && ! in_array($id, $ids, strict: true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
