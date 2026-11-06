<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

use App\Modules\Core\Enums\Market;
use App\Modules\Signals\Models\SignalWatch;

/**
 * Where a {@see SignalWatch} looks for news (ADR 0019, S8).
 *
 * **Not a market.** `Market` (Core) answers "which rules apply" and every case on it asserts a
 * legal position the business holds — ADR 0007 says we hold exactly two. This answers "where do we
 * look", which is a Serper `gl`/`hl` pair and carries no legal content at all. v1 had one column
 * doing both jobs, which was harmless while every watch was UAE and wrong in both directions the
 * moment one could span places.
 *
 * {@see self::market()} is therefore **nullable**: "we can search here, and we have not taken a view
 * on the law here" is a real state and worth being able to say. A region in that state contributes
 * nothing to a lead's outreach restriction and is **not offered** until somebody records a position
 * for it — see {@see self::isOffered()} and ADR 0019 §3.
 *
 * Values are the Serper `gl` codes, which is also how they read in a query string.
 */
enum SearchRegion: string
{
    /** United Arab Emirates — PDPL: prior consent for direct marketing (ADR 0007). */
    case Ae = 'ae';

    /** United Kingdom — legitimate interest, assessed per team by the LIA (ADR 0007). */
    case Gb = 'gb';

    /** Saudi Arabia. No compliance position recorded — not offered (⚖️ `compliance.md` §5). */
    case Sa = 'sa';

    /** United States. No compliance position recorded — not offered. */
    case Us = 'us';

    /** India. No compliance position recorded — not offered. */
    case In = 'in';

    /** Singapore. No compliance position recorded — not offered. */
    case Sg = 'sg';

    /** No country bias — Serper's own default. No compliance position recorded — not offered. */
    case Global = 'global';

    /**
     * The Serper `gl` country code, or null for the unbiased search.
     *
     * `global` is not a country, so it sends no `gl` at all rather than a made-up one.
     */
    public function gl(): ?string
    {
        return $this === self::Global ? null : $this->value;
    }

    /**
     * The Serper `hl` interface language for results from this region.
     *
     * This is what makes Arabic a content decision rather than an architectural one (ADR 0019 §5):
     * it was one product-wide `config('signals.hl')` documented as "v1: English only", and it is a
     * property of the region now. A definition may carry `query_templates_ar`; where it does not,
     * the English templates are used, so adding a region never silently empties a watch.
     */
    public function hl(): string
    {
        return match ($this) {
            // Arabic is available and deliberately **not** the default for the UAE: the templates
            // are English until somebody writes Arabic ones (see the module doc's pending
            // native-speaker pass). A machine-translated search query finds nothing and looks like
            // it works.
            self::Ae => (string) config('signals.regions.ae.hl', 'en'),
            default => (string) config("signals.regions.{$this->value}.hl", 'en'),
        };
    }

    /**
     * The compliance regime this region maps onto, or **null** where the business holds none.
     *
     * Null is not "unrestricted". It means the app cannot say what the rules are here, so a signal
     * from this region contributes nothing to a lead's restriction and the watch's own market is
     * the backstop (ADR 0019 §2). The regions in that state are not offered.
     */
    public function market(): ?Market
    {
        return match ($this) {
            self::Ae => Market::Uae,
            self::Gb => Market::Uk,
            self::Sa, self::Us, self::In, self::Sg, self::Global => null,
        };
    }

    /**
     * Whether a team may actually choose this region.
     *
     * Config rather than code, because enabling one is a **compliance** decision and not a release:
     * `signals.regions.<code>.offered` defaults to true only for the two regimes ADR 0007 covers.
     * The enum knows about the rest so the code is ready; the config is what says we are.
     */
    public function isOffered(): bool
    {
        return (bool) config("signals.regions.{$this->value}.offered", false);
    }

    /**
     * The places a watch in this region starts with, so a new watch is useful before anybody types.
     *
     * @return list<string>
     */
    public function defaultLocations(): array
    {
        /** @var list<string> $locations */
        $locations = array_values(array_filter(
            (array) config("signals.regions.{$this->value}.default_locations", []),
            static fn (mixed $place): bool => is_string($place) && trim($place) !== '',
        ));

        return $locations;
    }

    public function label(): string
    {
        return match ($this) {
            self::Ae => 'United Arab Emirates',
            self::Gb => 'United Kingdom',
            self::Sa => 'Saudi Arabia',
            self::Us => 'United States',
            self::In => 'India',
            self::Sg => 'Singapore',
            self::Global => 'Anywhere',
        };
    }

    /**
     * The one line the watch form shows beside the region, which has to name the **compliance**
     * consequence rather than just the place — a member choosing where to look is also choosing how
     * the resulting leads may be approached, and nothing else on that screen says so.
     */
    public function approachNote(): string
    {
        return match ($this) {
            self::Ae => 'Leads from the UAE are approached by introduction — LinkedIn or a referral, never cold email.',
            self::Gb => 'Leads from the UK may be emailed where your legitimate-interest assessment covers them.',
            default => 'No compliance position is recorded for this region yet.',
        };
    }

    /**
     * The regions a team may choose, in declaration order.
     *
     * @return list<self>
     */
    public static function offered(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $region): bool => $region->isOffered()));
    }

    /**
     * The region that matches a compliance market, for seeding a new watch from the team's own.
     */
    public static function forMarket(Market $market): self
    {
        return match ($market) {
            Market::Uae => self::Ae,
            Market::Uk => self::Gb,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $region): string => $region->value, self::cases());
    }

    /**
     * @return list<string>
     */
    public static function offeredValues(): array
    {
        return array_map(static fn (self $region): string => $region->value, self::offered());
    }
}
