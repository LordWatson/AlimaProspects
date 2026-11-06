<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Support\CompanyNameKey;
use App\Modules\Signals\Enums\WatchlistEntityType;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use App\Modules\Signals\Services\SignalSpendGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Adds names to a manual watchlist (S10) — one, or a column pasted out of a spreadsheet.
 *
 * **Duplicates are skipped, not refused.** A member pasting forty company names will paste some they
 * already have, and failing the whole request on the eleventh would make them diff two lists by
 * hand. The unique index is on the **normalised** name, so "ACME LTD" is already-present when
 * "Acme Ltd" is there — which is exactly the case pasting produces. The result says how many were
 * added and how many were already known, so the screen can say so rather than silently appearing to
 * do nothing.
 *
 * **It refuses a synced list.** A pipeline list's entries are an output: letting somebody add a name
 * the next sync would delete is a screen that argues with itself.
 *
 * **It stops at the cap** rather than refusing the batch, for the same reason as duplicates — and
 * the response says the list is full, so the member can prune rather than guess.
 */
final class AddWatchlistEntriesAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
        private readonly SignalSpendGuard $spend,
    ) {}

    /**
     * @param  list<array{name: string, entity_type?: string|null, aliases?: list<string>|null, domain?: string|null}>  $entries
     * @return array{added: int, duplicates: int, skipped: int}
     *
     * @throws SignalsException when the list is one a job maintains.
     */
    public function handle(Team $team, User $actor, SignalWatchlist $watchlist, array $entries): array
    {
        if (! $watchlist->allowsManualEntries()) {
            throw SignalsException::watchlistIsSynced();
        }

        // The team's own ceiling (S16 task 2), which is their plan's once Billing is registered.
        $max = $this->spend->maxWatchlistEntries($team);

        return $this->teamContext->run($team, fn (): array => DB::transaction(function () use ($team, $actor, $watchlist, $entries, $max): array {
            $taken = $watchlist->entries()->pluck('normalised_name')->all();

            $added = 0;
            $duplicates = 0;
            $skipped = 0;

            foreach ($entries as $entry) {
                $name = Str::limit(Str::squish($entry['name']), SignalWatchlistEntry::MAX_NAME, '');
                $key = CompanyNameKey::for($name);

                if ($name === '' || $key === '') {
                    // A row of punctuation, or a stray empty cell from a paste.
                    $skipped++;

                    continue;
                }

                if (in_array($key, $taken, strict: true)) {
                    $duplicates++;

                    continue;
                }

                if (count($taken) >= $max) {
                    $skipped++;

                    continue;
                }

                SignalWatchlistEntry::query()->create([
                    'team_id' => $team->getKey(),
                    'signal_watchlist_id' => $watchlist->getKey(),
                    'entity_type' => WatchlistEntityType::tryFrom((string) ($entry['entity_type'] ?? ''))
                        ?? WatchlistEntityType::Company,
                    'name' => $name,
                    'aliases' => $this->aliases($entry['aliases'] ?? null),
                    'domain' => $this->domain($entry['domain'] ?? null),
                    'lead_id' => null,
                    'is_active' => true,
                ]);

                $taken[] = $key;
                $added++;
            }

            $count = $watchlist->entries()->count();
            $watchlist->forceFill(['entries_count' => $count])->save();

            // Counts, never the names: a watchlist is full of company names and sometimes people's,
            // and the audit trail has no business holding a second copy of them (CLAUDE.md).
            $this->audit->log('signals.watchlist_entries_added', $watchlist, [
                'added' => $added,
                'duplicates' => $duplicates,
                'skipped' => $skipped,
                'entries' => $count,
            ], $actor);

            if ($skipped > 0) {
                Log::channel('signals')->info('Some watchlist entries were not added.', [
                    'team_id' => (int) $team->getKey(),
                    'signal_watchlist_id' => (int) $watchlist->getKey(),
                    'skipped' => $skipped,
                    'cap' => $max,
                ]);
            }

            return ['added' => $added, 'duplicates' => $duplicates, 'skipped' => $skipped];
        }));
    }

    /**
     * @return list<string>|null
     */
    private function aliases(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $out = [];

        foreach ($value as $alias) {
            if (! is_string($alias)) {
                continue;
            }

            $clean = Str::limit(Str::squish($alias), SignalWatchlistEntry::MAX_NAME, '');

            if ($clean !== '' && ! in_array($clean, $out, strict: true)) {
                $out[] = $clean;
            }
        }

        return array_slice($out, 0, SignalWatchlistEntry::MAX_ALIASES) ?: null;
    }

    private function domain(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = Str::lower(Str::squish($value));

        if ($clean === '') {
            return null;
        }

        if (Str::contains($clean, '/')) {
            $host = parse_url(Str::startsWith($clean, ['http://', 'https://']) ? $clean : 'https://'.$clean, PHP_URL_HOST);
            $clean = is_string($host) ? $host : $clean;
        }

        return Str::limit(Str::replaceStart('www.', '', $clean), 255, '');
    }
}
