<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Enums\EvidenceKind;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Models\LeadEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<LeadEvidence>
 */
class LeadEvidenceFactory extends Factory
{
    protected $model = LeadEvidence::class;

    /**
     * A Companies House filing — the strong, slow-decaying case, which is the one most tests want
     * as a baseline.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = 'https://find-and-update.company-information.service.gov.uk/company/'.$this->faker->numerify('########');

        return [
            'team_id' => Team::factory(),
            'lead_id' => Lead::factory(),
            'kind' => EvidenceKind::RegisterRecord,
            'source' => LeadSource::CompaniesHouse->value,
            'source_label' => 'Companies House',
            'signal_id' => null,
            'title' => 'Registered company record',
            'summary' => null,
            'source_url' => $url,
            'observed_at' => Carbon::now()->subMonths(6),
            'captured_at' => Carbon::now(),
            'reliability' => 95,
            'payload' => null,
            'dedupe_hash' => sha1(Str::lower($url)),
        ];
    }

    /**
     * A trigger event — the only kind that gives a lead a timing score.
     */
    public function signal(string $source = 'news', ?Carbon $publishedAt = null): self
    {
        return $this->state(function () use ($source, $publishedAt): array {
            $url = 'https://example.test/news/'.$this->faker->slug();

            return [
                'kind' => EvidenceKind::Signal,
                'source' => $source,
                'source_label' => 'Example Wire',
                'title' => 'Raises Series A funding',
                'summary' => 'Closed a funding round led by an institutional investor.',
                'source_url' => $url,
                'observed_at' => $publishedAt ?? Carbon::now()->subDays(3),
                'reliability' => 60,
                'dedupe_hash' => sha1(Str::lower($url)),
            ];
        });
    }

    /**
     * A fact a member has flagged as wrong: present, and worth nothing.
     */
    public function disputed(): self
    {
        return $this->afterCreating(static function (LeadEvidence $evidence): void {
            $evidence->forceFill(['is_disputed' => true])->save();
        });
    }

    /**
     * Aged by `$days`, so a test can watch the decay without travelling in time.
     */
    public function observedDaysAgo(int $days): self
    {
        return $this->state(fn (): array => ['observed_at' => Carbon::now()->subDays($days)]);
    }
}
