<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Console;

use App\Modules\LeadsWorkspace\Models\LeadExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes export files once they have expired (PL6).
 *
 * The reason this command exists is not tidiness. An export file is a team's **whole lead list** —
 * names, addresses, numbers — sitting on a disk outside the database's own retention sweeps, and a
 * file nobody deletes is the one that is still there when a bucket is misconfigured two years from
 * now. `leads-workspace.export.retain_days` is therefore a deletion deadline rather than a quota.
 *
 * **The file goes first and the row second**, which is the ordering that fails safely: a crash
 * between them leaves a row pointing at nothing (the download says "expired", which is true) rather
 * than a file nothing knows about (which nothing will ever delete).
 *
 * It queries without the tenancy scope on purpose — it is a platform sweep over every team's files,
 * run from the scheduler where there is no team in context anyway.
 *
 * `--dry-run` reports without deleting, because deletion is irreversible.
 */
final class PruneLeadExportsCommand extends Command
{
    protected $signature = 'leads:prune-exports
        {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete expired lead export files and their rows.';

    /** Per pass, so a long backlog is swept without one long-held lock. */
    private const int CHUNK = 200;

    public function handle(): int
    {
        $query = LeadExport::query()
            ->withoutGlobalScopes()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());

        if ((bool) $this->option('dry-run')) {
            $this->info('Would delete '.$query->count().' expired exports.');

            return self::SUCCESS;
        }

        $disk = Storage::disk((string) config('leads-workspace.export.disk', 'local'));
        $deleted = 0;
        $files = 0;

        // Re-running the eligibility query at each pass rather than reading ids once and deleting
        // by id: that is the bug `compliance:purge-expired` has already had, and a row whose
        // expiry moved between the two reads would be deleted on the strength of a stale answer.
        while (true) {
            $exports = $query->clone()->orderBy('id')->limit(self::CHUNK)->get();

            if ($exports->isEmpty()) {
                break;
            }

            foreach ($exports as $export) {
                $path = (string) $export->path;

                if ($path !== '' && $disk->exists($path)) {
                    if (! $disk->delete($path)) {
                        // The row is the only thing that knows this path. Keeping it means the
                        // next sweep tries again; deleting it would leave a team's whole lead list
                        // on the disk with nothing pointing at it.
                        $this->warn("Could not delete {$path}; leaving its row for the next sweep.");

                        continue;
                    }

                    $files++;
                }

                $export->delete();
                $deleted++;
            }
        }

        // Counts only — never a path, which names a team, or a row of the file.
        Log::info('Lead exports pruned.', [
            'exports' => $deleted,
            'files' => $files,
            'retain_days' => (int) config('leads-workspace.export.retain_days', 7),
        ]);

        $this->info("Deleted {$deleted} expired exports ({$files} files).");

        return self::SUCCESS;
    }
}
