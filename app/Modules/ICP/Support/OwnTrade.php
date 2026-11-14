<?php

declare(strict_types=1);

namespace App\Modules\ICP\Support;

use App\Modules\Core\Models\Team;
use App\Modules\Onboarding\Models\BusinessProfile;

/**
 * The team's own trade, for the profile editor to warn about (docs/plans/ai-relevance-plan.md P4).
 *
 * An ideal customer profile describes the team's **customers**, and nothing in the schema enforces
 * that: an industry signal naming the team's own trade is structurally valid and quietly turns
 * discovery into a search for competitors — the failure this product has had twice. The editor is
 * given the trade so it can say so beside the row.
 *
 * **It warns and never blocks**, which is why this hands the editor a string rather than failing a
 * save: a business that sells to its own trade (a white-label supplier to agencies) is a real case,
 * and the member is the one who knows.
 */
final class OwnTrade
{
    public function for(Team $team): ?string
    {
        $industry = BusinessProfile::query()->where('team_id', $team->getKey())->value('industry');

        return is_string($industry) && trim($industry) !== '' ? trim($industry) : null;
    }
}
