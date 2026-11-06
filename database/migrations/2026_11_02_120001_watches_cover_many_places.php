<?php

declare(strict_types=1);

use App\Modules\Core\Enums\Market;
use App\Modules\Signals\Enums\SearchRegion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A watch covers places, plural, and says where to look separately from which rules apply
     * (S8, ADR 0019).
     *
     * `location` (one string) becomes `locations` (a list) and `regions` (a list of
     * `SearchRegion`). `market` **stays**, demoted to what it always should have been: the team's
     * declared regime for this watch, used to seed defaults and to backstop a signal whose own
     * jurisdiction cannot be resolved. It no longer picks the Serper `gl` — each region carries its
     * own.
     *
     * Both new columns are **nullable**, and that is deliberate rather than lazy: `locations` is
     * added, backfilled and then read with a fallback, which avoids a `->change()` to NOT NULL.
     * S7 learned that lesson twice over — a `->change()` means a table rebuild on SQLite and a
     * constraint only MySQL enforces, so the two engines disagree about a column the suite never
     * exercises. "Null means not configured" is the same shape `ignore_domains` already uses, and
     * `SignalWatchData` is what guarantees at least one place on the way in.
     *
     * The old `location` column is dropped by the **next** migration, so each is reversible on its
     * own and rolling back does not have to get an order right.
     */
    public function up(): void
    {
        Schema::table('signal_watches', function (Blueprint $table): void {
            // list<string>, max `signals.max_locations_per_watch`. The product of these and a
            // definition's templates is what a run pays for.
            $table->json('locations')->nullable()->after('location');
            // list<SearchRegion>. Each carries its own Serper `gl` and `hl`.
            $table->json('regions')->nullable()->after('locations');
        });

        // Backfill: one place, one region — so a v1 watch produces byte-identical queries.
        DB::table('signal_watches')
            ->orderBy('id')
            ->select(['id', 'location', 'market'])
            ->chunk(200, function (iterable $watches): void {
                foreach ($watches as $watch) {
                    $location = is_string($watch->location) ? trim($watch->location) : '';
                    $market = Market::tryFrom((string) $watch->market) ?? Market::Uae;

                    DB::table('signal_watches')->where('id', $watch->id)->update([
                        'locations' => json_encode(
                            $location === '' ? [] : [$location],
                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                        ),
                        'regions' => json_encode(
                            [SearchRegion::forMarket($market)->value],
                            JSON_UNESCAPED_SLASHES,
                        ),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('signal_watches', function (Blueprint $table): void {
            $table->dropColumn(['locations', 'regions']);
        });
    }
};
