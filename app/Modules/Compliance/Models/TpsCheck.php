<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use App\Modules\Compliance\Enums\TpsRegister;
use App\Modules\Compliance\Services\TpsScreener;
use App\Modules\Core\Enums\TpsStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One cached TPS/CTPS verdict (PL5).
 *
 * **Platform reference data, so no `BelongsToTeam`** — and the absence is the statement, the same
 * way it is on `PlatformSetting`. Whether a number is on the Telephone Preference Service is a fact
 * about the number, the register charges per lookup, and keying it per tenant would bill two
 * customers twice for the same answer while letting one hold a stale "clear" the other has already
 * replaced.
 *
 * It holds a **hash, never a number**. Nothing in the product reads a number back out of here;
 * every question is "what is the verdict for this hash", which is why there is no accessor that
 * could tempt one.
 *
 * Always read and written through {@see TpsScreener}: the staleness window, the register the
 * subscriber type picks, and the "unknown is not clear" rule all live there, and a direct read
 * would get the row without any of them.
 *
 * @property int $id
 * @property string $phone_hash
 * @property TpsRegister $register
 * @property TpsStatus $result
 * @property string $provider
 * @property Carbon $checked_at
 */
class TpsCheck extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'phone_hash',
        'register',
        'result',
        'provider',
        'checked_at',
    ];

    /**
     * Whether this verdict is still current.
     *
     * The window is `compliance.tps.recheck_days` — 28 by default, which is the cadence the
     * register itself publishes updates on. A verdict past it is re-screened rather than deleted:
     * an expired row with nothing in its place reads as "never checked", and a provider outage
     * would then quietly turn every screened number back into an unscreened one.
     */
    public function isFresh(?int $withinDays = null): bool
    {
        $days = $withinDays ?? max(1, (int) config('compliance.tps.recheck_days', 28));

        return $this->checked_at->greaterThan(Carbon::now()->subDays($days));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'register' => TpsRegister::class,
            'result' => TpsStatus::class,
            'checked_at' => 'datetime',
        ];
    }
}
