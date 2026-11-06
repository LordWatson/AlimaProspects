<?php

declare(strict_types=1);

namespace App\Modules\Signals\Contracts;

use App\Modules\Core\Models\Team;
use App\Modules\Signals\Data\SignalAllowance;
use App\Modules\Signals\Services\SignalSpendGuard;
use App\Modules\Signals\Services\StubSignalQuotaSource;

/**
 * Reports what a team is allowed to spend on Signals (S16 task 1).
 *
 * The fourth of the seams Billing fills in, and built to exactly the shape of the other three —
 * `AiPlatform\Contracts\CreditSource`, `LeadSourcing\Contracts\LeadQuotaSource` and
 * `Auth\Contracts\SeatLimitSource`. The module ships its own default binding
 * ({@see StubSignalQuotaSource}, the config floors), Billing is registered **last** and binds a
 * plan-backed source over it, and nothing in this module imports `App\Modules\Billing`
 * (`CLAUDE.md` §3).
 *
 * One method, because a limit is only ever asked for one team at a time and the answer is cheap to
 * assemble. {@see SignalSpendGuard} is the only thing that should call it: it is what turns an
 * allowance into a refusal, counts the usage against it, and is therefore the one place a new
 * caller can be sure is measuring the same thing as everything else.
 */
interface SignalQuotaSource
{
    public function allowanceFor(Team $team): SignalAllowance;
}
