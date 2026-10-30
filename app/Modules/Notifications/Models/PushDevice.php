<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Models\User;
use App\Modules\Notifications\Enums\PushPlatform;
use Database\Factories\PushDeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A device registered for push (Phase 12). Owned by a **person**, not a team — the same handset
 * receives notifications for every team its owner belongs to, and whether to send at all is the
 * per-team preference's decision.
 *
 * `token` is a bearer credential for sending *to* the device: it is `Hidden`, never logged, and
 * never sent back out in a response. `token_hash` is what the unique index and every lookup use,
 * because MySQL cannot index 512 characters of utf8mb4 and equality is all a token lookup needs.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string $token_hash
 * @property PushPlatform $platform
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['token', 'token_hash'])]
class PushDevice extends Model
{
    /** @use HasFactory<PushDeviceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'token',
        'token_hash',
        'platform',
        'last_used_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The lookup key for a token. Unsalted and deterministic, because recognising the same device
     * re-registering is the whole function.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', trim($token));
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'platform' => PushPlatform::class,
            'last_used_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PushDeviceFactory
    {
        return PushDeviceFactory::new();
    }
}
