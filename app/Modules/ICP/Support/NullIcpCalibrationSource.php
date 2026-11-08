<?php

declare(strict_types=1);

namespace App\Modules\ICP\Support;

use App\Modules\ICP\Contracts\IcpCalibrationSource;
use App\Modules\ICP\Data\IcpCalibration;
use App\Modules\ICP\Models\Icp;

/**
 * The permissive default for {@see IcpCalibrationSource}: there is no calibration to show. Bound by
 * this module's provider and replaced by Scoring's real `IcpCalibrator`, so a build without Scoring
 * (or a test that doesn't need it) renders the ICP screen with an empty calibration rather than a
 * missing binding.
 */
final class NullIcpCalibrationSource implements IcpCalibrationSource
{
    public function summarise(Icp $icp): IcpCalibration
    {
        return IcpCalibration::empty();
    }
}
