<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

/**
 * Where a watchlist's entries come from (S10).
 *
 * The app branches on this — a `pipeline` list is rebuilt nightly from the team's leads and a
 * `manual` one is never touched by anything but a member — so it is an enum rather than a row, which
 * is the test ADR 0018 §1 draws.
 */
enum WatchlistSourceKind: string
{
    /** A member typed or pasted the names. Nothing syncs it; nothing removes an entry but them. */
    case Manual = 'manual';

    /**
     * Mirrored from the team's own pipeline, nightly.
     *
     * **This is the one the phase exists for.** A discovery feed can be switched off; a monitor over
     * the accounts somebody is already working cannot be, without going blind.
     */
    case Pipeline = 'pipeline';

    /**
     * A saved view or segment of leads, rather than the whole pipeline.
     *
     * Declared and **not yet offered**: `SavedView` is a Phase 10b concept and wiring a watchlist to
     * one needs its filters replayed on a schedule, which is a different job from "every lead in
     * these stages". A case with no implementation would be worse than no case, so
     * {@see self::isOffered()} says so and the save path refuses it.
     */
    case Segment = 'segment';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Names I add',
            self::Pipeline => 'My pipeline',
            self::Segment => 'A saved view',
        };
    }

    /**
     * What this kind does, for the member choosing between them.
     */
    public function description(): string
    {
        return match ($this) {
            self::Manual => 'You add the companies and people. Alima watches exactly those.',
            self::Pipeline => 'Alima mirrors the leads you are working and keeps the list up to date overnight.',
            self::Segment => 'Alima follows a saved view of leads.',
        };
    }

    /** Whether a member may choose it today. */
    public function isOffered(): bool
    {
        return $this !== self::Segment;
    }

    /** Whether a scheduled job rebuilds it. */
    public function isSynced(): bool
    {
        return $this === self::Pipeline;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }

    /**
     * The kinds a job rebuilds, as stored values — for a `whereIn` (S16's retention sweep).
     *
     * Derived from {@see self::isSynced()} rather than listed, so a third synced kind cannot be
     * added to the enum and forgotten here. The distinction matters to the sweep: an entry with no
     * lead is an **orphan** on a synced list and the normal case on a manual one.
     *
     * @return list<string>
     */
    public static function syncedValues(): array
    {
        return array_values(array_map(
            static fn (self $kind): string => $kind->value,
            array_filter(self::cases(), static fn (self $kind): bool => $kind->isSynced()),
        ));
    }

    /**
     * @return list<self>
     */
    public static function offered(): array
    {
        // Pipeline first: it is the answer for almost everybody, it needs nothing typed, and the
        // screen pre-selects it. Declaration order is `manual, pipeline` because `manual` is the
        // simpler case to read in the enum, which is not the order to *offer* them in.
        $order = [self::Pipeline, self::Manual, self::Segment];

        return array_values(array_filter($order, static fn (self $kind): bool => $kind->isOffered()));
    }
}
