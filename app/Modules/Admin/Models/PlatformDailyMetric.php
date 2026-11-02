<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Admin\Enums\PlatformMetric;
use App\Modules\Admin\Services\Platform\PlatformMetricStore;
use App\Modules\Billing\Models\UsageMeter;
use App\Modules\Core\Concerns\BelongsToTeam;
use Database\Factories\PlatformDailyMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One point of the platform's nightly rollup (Phase 19c): `(date, metric, dimension) → value`.
 *
 * **Deliberately not {@see BelongsToTeam}.** These rows are the business's own numbers, not any
 * tenant's — a global tenant scope would hide every one of them the moment a team was active, and
 * the panel reads them with tenancy already cleared. Where a figure *is* per tenant, the tenant is
 * carried in `dimension` as `t<id>` rather than in a `team_id` column, for the same reason the
 * table has one `value` column: a second shape would have meant a second table.
 *
 * The model is a thin accessor. Everything that reads or writes it goes through
 * {@see PlatformMetricStore}, which is where the UTC date discipline and the unknown-metric
 * handling live — exactly as {@see UsageMeter} is written through its ledger rather than directly.
 *
 * @property int $id
 * @property string $date `Y-m-d`; see the casts note
 * @property string $metric
 * @property string $dimension
 * @property int $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PlatformDailyMetric extends Model
{
    /** @use HasFactory<PlatformDailyMetricFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'date',
        'metric',
        'dimension',
        'value',
    ];

    /**
     * The metric this row is for, or **null when the stored string is not one we know**.
     *
     * Null rather than a cast exception, and that is the point: a case renamed in
     * {@see PlatformMetric} leaves historical rows behind, and a report that threw on them would
     * take the whole screen down rather than quietly omitting a figure nobody can define any more.
     * The rollup rewrites what it recognises; it never deletes what it does not.
     */
    public function metric(): ?PlatformMetric
    {
        return PlatformMetric::tryFrom($this->metric);
    }

    /**
     * **`date` is deliberately not cast to a date object**, which is the one surprising thing
     * about this model.
     *
     * Eloquent writes a date-cast attribute through `fromDateTime()`, which formats it with the
     * connection's datetime format — so a row saved through the model would land as
     * `2026-10-22 00:00:00` while {@see PlatformMetricStore::replaceDay()} writes the bare
     * `2026-10-22` its bulk insert needs. The two forms then compare differently: a window's
     * inclusive upper bound `<= '2026-10-31'` matches the bare form and **not** the padded one, so
     * half the rollup would silently drop out of every window depending on which code path wrote
     * it.
     *
     * One format, everywhere: `Y-m-d`. The store reads and writes `Y-m-d` strings throughout
     * (`series()` keys, `latestDay()`), the column is a real `DATE` in the database, and nothing
     * in the product needs a Carbon instance of it. Where a caller does, it parses one — in UTC,
     * which is the only clock this table has.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
        ];
    }

    protected static function newFactory(): PlatformDailyMetricFactory
    {
        return PlatformDailyMetricFactory::new();
    }
}
