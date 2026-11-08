<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Enums\EvidenceVerdict;
use App\Modules\LeadSourcing\Models\LeadEvidence;
use App\Modules\LeadSourcing\Models\LeadEvidenceFeedback;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadEvidenceFeedback>
 */
class LeadEvidenceFeedbackFactory extends Factory
{
    protected $model = LeadEvidenceFeedback::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'lead_evidence_id' => LeadEvidence::factory(),
            'user_id' => User::factory(),
            'verdict' => EvidenceVerdict::Useful,
            'note' => null,
        ];
    }

    /**
     * The one verdict that changes a lead's confidence.
     */
    public function wrong(?string $note = null): self
    {
        return $this->state(fn (): array => [
            'verdict' => EvidenceVerdict::Wrong,
            'note' => $note,
        ]);
    }
}
