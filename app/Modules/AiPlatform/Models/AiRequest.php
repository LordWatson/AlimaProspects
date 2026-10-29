<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Models;

use App\Models\User;
use App\Modules\AiPlatform\Enums\AiRequestStatus;
use App\Modules\AiPlatform\Enums\AiTier;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Support\TeamContext;
use Database\Factories\AiRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per AI call (or cache hit): the per-team token + cost ledger behind credit enforcement
 * and Reporting. Team-scoped via {@see BelongsToTeam} — the active team is auto-assigned from
 * {@see TeamContext} at log time. Holds IDs, counts and cost only; never
 * prompt inputs/outputs or personal data.
 *
 * @property int $id
 * @property int|null $team_id
 * @property int|null $user_id
 * @property string $prompt_key
 * @property int $prompt_version
 * @property AiTier $tier
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int|null $cache_read_input_tokens
 * @property int $cost_micros
 * @property string $currency
 * @property int $latency_ms
 * @property bool $cache_hit
 * @property AiRequestStatus $status
 * @property string|null $error_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiRequest extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<AiRequestFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'user_id',
        'prompt_key',
        'prompt_version',
        'tier',
        'model',
        'input_tokens',
        'output_tokens',
        'cache_read_input_tokens',
        'cost_micros',
        'currency',
        'latency_ms',
        'cache_hit',
        'status',
        'error_code',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'prompt_version' => 'integer',
            'tier' => AiTier::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_input_tokens' => 'integer',
            'cost_micros' => 'integer',
            'latency_ms' => 'integer',
            'cache_hit' => 'boolean',
            'status' => AiRequestStatus::class,
        ];
    }

    protected static function newFactory(): AiRequestFactory
    {
        return AiRequestFactory::new();
    }
}
