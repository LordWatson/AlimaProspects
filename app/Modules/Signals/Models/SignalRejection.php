<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Signals\Enums\SignalRejectionVerdict;
use App\Modules\Signals\Services\SignalRejectionLedger;
use Database\Factories\SignalRejectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An article this team's classifier has already said no to (S11).
 *
 * It exists to close the S3 trade-off (b): a rejected item was forgotten, so every run paid to
 * classify the same weekly round-ups again. Written by {@see SignalRejectionLedger} and read by the
 * run before it batches anything.
 *
 * **It holds hashes, a verdict and counts.** No headline, no URL, no outlet, no name — which is
 * what makes it cheap to retain for 30 days and what keeps it out of the DSAR surface: there is
 * nothing in a row that identifies a person, or indeed anything, to anyone who does not already
 * have the article.
 *
 * `definition_fingerprint` is the part worth understanding. It is a hash of the **set of signal
 * type keys that were offered** when the verdict was reached, so "rejected for the same definition"
 * has a meaning once a watch offers six types at once: a team that adds a type has asked a
 * different question about the same article and gets a fresh answer, and a team that has changed
 * nothing is not asked to pay twice.
 *
 * Tenant-scoped via {@see BelongsToTeam}, and nothing routes to it — see {@see SignalCluster} for
 * the same reasoning about a record the module keeps of its own work.
 *
 * @property int $id
 * @property int $team_id
 * @property string $dedupe_hash
 * @property string $definition_fingerprint
 * @property SignalRejectionVerdict $verdict
 * @property int $seen_count
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SignalRejection extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalRejectionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'dedupe_hash',
        'definition_fingerprint',
        'verdict',
        'seen_count',
        'first_seen_at',
        'last_seen_at',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'verdict' => SignalRejectionVerdict::class,
            'seen_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SignalRejectionFactory
    {
        return SignalRejectionFactory::new();
    }
}
