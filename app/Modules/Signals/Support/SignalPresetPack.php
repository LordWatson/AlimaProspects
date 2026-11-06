<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support;

/**
 * A vertical bundle of platform presets, for browsing the library (S9).
 *
 * **Why this is in `Support/` and not a column.** A pack is how the *platform* organises its own
 * presets — merchandising, in the honest sense: "here are the five events a recruiter cares about".
 * It is not a property of a signal type, and least of all of a team's own. Putting it on
 * `signal_definitions` would have stamped a marketing label onto every tenant row, and a team's
 * hand-written type would have needed a pack it could never belong to. So the pack lives in the
 * catalogue beside the presets it groups, and {@see SignalPresetCatalogue::packFor()} resolves it by
 * key for the library screen. A preset key the catalogue no longer knows falls into no pack and
 * still works — which is the right failure, since the row is the thing a team is paying to run.
 *
 * `audience()` matters as much as `label()`. A pack nobody recognises is a filter nobody presses,
 * and "Recruitment & staffing" tells somebody less than "You place people, and you want to know who
 * is about to need them" does.
 */
enum SignalPresetPack: string
{
    /**
     * Events that matter whoever you sell to. Where the S8 UK starter set lives — a change of
     * ownership, a funding round or an insolvency is not a vertical's concern, it is everybody's.
     */
    case General = 'general';

    /** The v1 thesis, promoted to a pack: who just came into money and might buy a villa. */
    case LuxuryRealEstate = 'luxury_real_estate';

    case Recruitment = 'recruitment';

    case B2bSaas = 'b2b_saas';

    case WealthManagement = 'wealth_management';

    case Construction = 'construction';

    case MaAdvisory = 'ma_advisory';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General business events',
            self::LuxuryRealEstate => 'Luxury real estate',
            self::Recruitment => 'Recruitment & staffing',
            self::B2bSaas => 'B2B software',
            self::WealthManagement => 'Wealth management',
            self::Construction => 'Construction & fit-out',
            self::MaAdvisory => 'M&A and corporate finance',
        };
    }

    /**
     * Who this pack is for, in their own terms rather than ours.
     */
    public function audience(): string
    {
        return match ($this) {
            self::General => 'Start here. The events that change a company’s plans, whatever you sell.',
            self::LuxuryRealEstate => 'You sell property or private assets, and you want to know who just came into money.',
            self::Recruitment => 'You place people, and you want to know who is about to need them.',
            self::B2bSaas => 'You sell software, and you want the moment a team’s problem becomes urgent.',
            self::WealthManagement => 'You manage money, and you want the liquidity event before the adviser call.',
            self::Construction => 'You build or fit out, and you want the project before the tender.',
            self::MaAdvisory => 'You advise on deals, and you want the ownership change as it is filed.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $pack): string => $pack->value, self::cases());
    }
}
