<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Models;

use App\Modules\AiPlatform\Enums\AiTier;
use Database\Factories\AiPromptTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single versioned prompt in the registry. Referenced by {@see $key} + {@see $version}; the body
 * carries `{{placeholder}}` variables and (for structured prompts) a JSON {@see $schema} the
 * response is validated against. Global configuration — not tenant data — so it is not team-scoped.
 *
 * @property int $id
 * @property string $key
 * @property int $version
 * @property AiTier $tier
 * @property string|null $system
 * @property string $body
 * @property array<string, mixed>|null $schema
 * @property int|null $max_tokens
 * @property float|null $temperature
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiPromptTemplate extends Model
{
    /** @use HasFactory<AiPromptTemplateFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'version',
        'tier',
        'system',
        'body',
        'schema',
        'max_tokens',
        'temperature',
        'description',
        'is_active',
    ];

    public function hasSchema(): bool
    {
        return $this->schema !== null && $this->schema !== [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'tier' => AiTier::class,
            'schema' => 'array',
            'max_tokens' => 'integer',
            'temperature' => 'float',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): AiPromptTemplateFactory
    {
        return AiPromptTemplateFactory::new();
    }
}
