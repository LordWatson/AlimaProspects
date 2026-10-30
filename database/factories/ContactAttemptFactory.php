<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Models\ContactAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactAttempt>
 */
class ContactAttemptFactory extends Factory
{
    protected $model = ContactAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'lead_id' => Lead::factory(),
            'lead_contact_id' => null,
            'outreach_message_id' => null,
            'created_by' => User::factory(),
            'channel' => OutreachChannel::Email,
            'outcome' => ContactOutcome::Pending,
            'occurred_at' => now(),
            'notes' => null,
        ];
    }

    public function channel(OutreachChannel $channel): self
    {
        return $this->state(fn (): array => ['channel' => $channel]);
    }

    public function outcome(ContactOutcome $outcome): self
    {
        return $this->state(fn (): array => ['outcome' => $outcome]);
    }

    public function occurredAt(string $when): self
    {
        return $this->state(fn (): array => ['occurred_at' => $when]);
    }
}
