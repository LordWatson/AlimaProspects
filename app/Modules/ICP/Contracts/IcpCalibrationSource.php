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

    /**
     * What the team's own use of a profile says about it, for the refinement suggestions
     * (relevance P4): the leads members marked a good or a bad fit, and what recent searches found
     * and left out. A marked lead is **one line of company-level fact** — a trade, a size, a place
     * — and never its name: it is evidence about the profile rather than about anybody. A
     * left-out company is the relevance gate's own one-line reason, which is model-written prose
     * and may mention the company it is about; callers wrap it as untrusted content.
     *
     * @return array{good: list<string>, bad: list<string>, rejected: list<string>}
     */
    public function feedback(Icp $icp): array;
}
