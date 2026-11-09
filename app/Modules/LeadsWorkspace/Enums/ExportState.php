<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Enums;

/**
 * Where an export has got to (PL6).
 *
 * Three states and no `running`: a worker holding the job is still `pending` from the member's
 * point of view, and a fourth state would be one more thing to get back to `failed` when a worker
 * is killed outright.
 */
enum ExportState: string
{
    /** Asked for; the file does not exist yet. */
    case Pending = 'pending';

    /** Written, on the private disk, downloadable until `expires_at`. */
    case Ready = 'ready';

    /** The job gave up. The allowance has been released. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Preparing',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
        };
    }
}
