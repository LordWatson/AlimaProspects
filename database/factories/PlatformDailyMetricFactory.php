<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Admin\Enums\PlatformMetric;
use App\Modules\Admin\Models\PlatformDailyMetric;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformDailyMetric>
 */
class PlatformDailyMetricFactory extends Factory
{
    protected $model = PlatformDailyMetric::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Yesterday in **UTC**, which is the only clock this table has. `today()` would be the
            // app timezone's today, and a rollup row dated by the wrong clock is a figure in the
            // wrong bucket.
            'date' => CarbonImmutable::now('UTC')->subDay()->toDateString(),
            'metric' => PlatformMetric::Signups->value,
            'dimension' => '',
            'value' => 0,
        ];
    }

    public function metric(PlatformMetric $metric, string $dimension = ''): self
    {
        return $this->state(fn (): array => [
            'metric' => $metric->value,
            'dimension' => $dimension,
        ]);
    }

    public function on(CarbonImmutable|string $date): self
    {
        return $this->state(fn (): array => [
            'date' => $date instanceof CarbonImmutable ? $date->toDateString() : $date,
        ]);
    }

    public function worth(int $value): self
    {
        return $this->state(fn (): array => ['value' => $value]);
    }
}
