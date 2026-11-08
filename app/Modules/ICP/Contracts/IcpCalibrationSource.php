<?php

declare(strict_types=1);

namespace App\Modules\ICP\Contracts;

use App\Modules\ICP\Data\IcpCalibration;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Support\NullIcpCalibrationSource;

/**
 * How the ICP screen asks "how is this profile actually grading our leads?" without importing the
 * Scoring module — the same seam-plus-permissive-default pattern Scoring uses for `LeadSignalSource`
 * and Billing for `CreditSource`. ICP owns the contract and ships a null default
 * ({@see NullIcpCalibrationSource}); `ScoringServiceProvider` (registered
 * after this module's) binds the real implementation, which reads `lead_scores`. This keeps the
 * dependency pointing one way — Scoring already depends on ICP, so ICP must not depend on Scoring.
 *
 * The default answering "nothing yet" is the right direction: a build or a test without Scoring, or
 * a brand-new profile nothing has been graded against, truthfully has no calibration to show.
 */
interface IcpCalibrationSource
{
    public function summarise(Icp $icp): IcpCalibration;
}
