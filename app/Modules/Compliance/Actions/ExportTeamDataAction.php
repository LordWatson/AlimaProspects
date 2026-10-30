<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Services\TeamDataArchiver;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Hands a team its own data as a downloadable ZIP (docs/compliance.md §3.6).
 *
 * **Audited before the file is sent**, and audited whether or not the download completes. Who
 * exported a team's entire prospect database and when is a security event (§3.8), and an entry
 * written only on success would be missing exactly the attempts worth looking at.
 *
 * The archive is a temporary file deleted after the response is sent. Nothing is written to the
 * application's storage: an export sitting in `storage/app` is a copy of the whole tenant's
 * personal data with no retention rule of its own, which is the kind of second copy the retention
 * policy exists to prevent.
 */
final class ExportTeamDataAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly TeamDataArchiver $archiver,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Team $team, User $member): BinaryFileResponse
    {
        return $this->teamContext->run($team, function () use ($team, $member): BinaryFileResponse {
            $this->audit->log('compliance.team_data_exported', $team, [
                'format' => 'zip',
            ], $member);

            Log::channel('compliance')->info('Team data export requested.', [
                'team_id' => (int) $team->getKey(),
                'user_id' => (int) $member->getKey(),
            ]);

            $path = $this->archiver->write($team);

            $response = response()
                ->download($path, $this->filename($team), [
                    'Content-Type' => 'application/zip',
                ], ResponseHeaderBag::DISPOSITION_ATTACHMENT)
                ->deleteFileAfterSend();

            // Nothing between the member and this file may keep a copy of a whole tenant's prospect
            // database. Set through the response rather than as a header, because
            // `BinaryFileResponse` writes `public` into `Cache-Control` itself and would overwrite
            // a plain header with it.
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');
            $response->headers->addCacheControlDirective('no-cache');
            $response->headers->addCacheControlDirective('must-revalidate');

            return $response;
        });
    }

    /**
     * A filename a member can find again a month later: the team, slugged, and the date.
     */
    private function filename(Team $team): string
    {
        $slug = Str::slug($team->name);

        return sprintf('alima-%s-export-%s.zip', $slug === '' ? 'team' : $slug, now()->format('Y-m-d'));
    }
}
