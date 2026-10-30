<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Models\OutreachSuppression;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutreachSuppression>
 */
class OutreachSuppressionFactory extends Factory
{
    protected $model = OutreachSuppression::class;

    /**
     * A global opt-out by default, since that is the entry whose behaviour most tests are about.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => null,
            'created_by' => null,
            'scope' => SuppressionScope::Global,
            'scope_key' => SuppressionScope::Global->keyFor(null),
            'type' => SuppressionType::Email,
            'value_hash' => hash('sha256', 'stop@example.com'),
            'value_preview' => 's***@example.com',
            'reason' => SuppressionReason::OptOut,
            'note' => null,
            'suppressed_at' => now(),
        ];
    }

    /**
     * An entry for a real value, hashed and previewed exactly as the service would. The way tests
     * should build one: hand-writing a hash is how a test ends up passing against a row the
     * production lookup would never match.
     */
    public function forValue(SuppressionType $type, string $value): self
    {
        $normalised = $type->normalise($value) ?? $value;

        return $this->state(fn (): array => [
            'type' => $type,
            'value_hash' => hash('sha256', $normalised),
            'value_preview' => $type->preview($normalised),
        ]);
    }

    public function forTeam(int $teamId, SuppressionReason $reason = SuppressionReason::Manual): self
    {
        return $this->state(fn (): array => [
            'team_id' => $teamId,
            'scope' => SuppressionScope::Team,
            'scope_key' => SuppressionScope::Team->keyFor($teamId),
            'reason' => $reason,
        ]);
    }
}
