<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Data;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\CreateLeadAction;

/**
 * What {@see CreateLeadAction} did: the lead the member ended up on, and whether it was one the
 * team already had.
 *
 * The flag exists so the screen can **say so**. Adding a company the team already has folds what
 * was typed into the existing lead — the right thing to do with the data, and a very confusing
 * thing to do silently, because the member would then be looking at notes, tags and a stage they
 * didn't put there. Returning a result rather than a bare `Lead` saves the controller from having
 * to guess by comparing timestamps.
 *
 * A plain readonly class rather than a `Spatie\LaravelData\Data` one, unlike its neighbours here:
 * it carries an Eloquent model and is never serialised to a client — the screens get a
 * `LeadResource` or a redirect, not this.
 */
final readonly class LeadCreationResult
{
    public function __construct(
        public Lead $lead,
        /** True when the details were folded into a lead the team already had. */
        public bool $merged,
    ) {}
}
