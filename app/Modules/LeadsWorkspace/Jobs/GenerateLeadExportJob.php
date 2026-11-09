<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Jobs;

use App\Modules\LeadsWorkspace\Actions\GenerateLeadExportAction;
use App\Modules\LeadsWorkspace\Enums\ExportState;
use App\Modules\LeadsWorkspace\Models\LeadExport;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds an export too big for a member to wait on, then tells them it is ready (PL6).
 *
 * The work itself is {@see GenerateLeadExportAction}, shared with the request path — this class is
 * only the queue's half of it: once, with retries, and with somebody told at the end either way.
 *
 * **Unique on the export row**, because a retry and a second dispatch are the same instruction and
 * running it twice would write a second file and notify twice. It is addressed **by id** rather
 * than by model so a row deleted while the job waited is a quiet return rather than a failure.
 */
final class GenerateLeadExportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Three attempts: the realistic failure is the disk or the database, and both come back. */
    public int $tries = 3;

    /** Long enough for a six-figure export, short enough that a wedged worker is noticed. */
    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(private readonly int $exportId) {}

    public function uniqueId(): string
    {
        return 'lead-export:'.$this->exportId;
    }

    /**
     * Released well before `$timeout` × `$tries` so a crashed worker cannot lock the export out of
     * being rebuilt for ever.
     */
    public function uniqueFor(): int
    {
        return 1800;
    }

    public function handle(
        GenerateLeadExportAction $generate,
        NotificationDispatcher $notifications,
    ): void {
        $export = LeadExport::query()->find($this->exportId);

        if (! $export instanceof LeadExport || $export->state === ExportState::Ready) {
            return;
        }

        $generate->handle($export);

        $member = $export->user;

        if ($member === null) {
            return; // The member who asked has left the team; the file is still the team's.
        }

        // Counts and an id, never a row of the file: this payload is stored and reaches a phone.
        $notifications->send($export->team, $member, NotificationType::ExportReady, [
            'export_id' => (int) $export->getKey(),
            'rows' => $export->row_count,
            'suppressed' => $export->suppressed_count,
        ]);
    }

    /**
     * The export is marked failed and the allowance given back.
     *
     * The release matters more than the state does: a hold nobody settles sits against the team's
     * allowance until the month turns over, which is a team being quietly charged for a file they
     * never received.
     */
    public function failed(?Throwable $e): void
    {
        $export = LeadExport::query()->find($this->exportId);

        if (! $export instanceof LeadExport) {
            return;
        }

        // Shared with the request path, and it leaves a `Ready` row alone: a worker killed after
        // the file was written still reports a failure, and the member has a file.
        app(GenerateLeadExportAction::class)->fail($export);

        // The class, never the message: an exception's message can carry a row of the file.
        Log::error('Lead export failed.', [
            'export_id' => $this->exportId,
            'team_id' => $export->team_id,
            'exception' => $e === null ? null : $e::class,
        ]);
    }
}
