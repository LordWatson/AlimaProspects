<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Compliance\Enums\LiaStatus;
use App\Modules\Compliance\Models\LiaRecord;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LiaRecord>
 */
class LiaRecordFactory extends Factory
{
    protected $model = LiaRecord::class;

    /**
     * Defaults to an **active** assessment, because that is the state most tests need in the
     * background: the discovery gate is closed without one, and a factory that produced drafts
     * would make every unrelated sourcing test fail for the wrong reason.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'market' => Market::Uk,
            'status' => LiaStatus::Active,
            'purpose' => 'To identify UK businesses whose published characteristics match our ideal-customer profile, and approach them about a service relevant to their trade.',
            'necessity' => 'Public company and business-listing data is the only way to identify these businesses at all; we hold role-based contact points in preference to personal ones.',
            'balancing' => 'The data is business contact data, the approach is relevant to the recipient’s trade, an opt-out is honoured immediately and globally, and nothing is retained past its window.',
            'safeguards' => 'Role-based data preferred, opt-out honoured platform-wide, retention windows enforced by a nightly sweep.',
            'signals_scope' => null,
            'completed_at' => now()->subDay(),
            'review_due_at' => now()->addMonths((int) config('compliance.lia.review_after_months', 12)),
            'version' => 1,
        ];
    }

    /**
     * Written down but not signed off — the state the gate must refuse.
     */
    public function draft(): self
    {
        return $this->state(fn (): array => [
            'status' => LiaStatus::Draft,
            'completed_at' => null,
            'review_due_at' => null,
        ]);
    }

    /**
     * Signed off, but past its review date. Distinct from a draft: the gate closes either way, and
     * the member is told something different.
     */
    public function expired(): self
    {
        return $this->state(fn (): array => [
            'status' => LiaStatus::Active,
            'completed_at' => now()->subMonths(18),
            'review_due_at' => now()->subDay(),
        ]);
    }

    public function withdrawn(): self
    {
        return $this->state(fn (): array => [
            'status' => LiaStatus::Withdrawn,
            'withdrawn_at' => now()->subDay(),
        ]);
    }

    public function forMarket(Market $market): self
    {
        return $this->state(fn (): array => ['market' => $market]);
    }
}
