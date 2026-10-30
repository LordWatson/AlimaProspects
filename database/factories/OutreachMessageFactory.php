<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Core\Enums\SupportedLocale;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\OutreachMessageStatus;
use App\Modules\Outreach\Enums\OutreachTone;
use App\Modules\Outreach\Models\OutreachMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutreachMessage>
 */
class OutreachMessageFactory extends Factory
{
    protected $model = OutreachMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'lead_id' => Lead::factory(),
            'lead_contact_id' => null,
            'outreach_template_id' => null,
            'created_by' => User::factory(),
            'channel' => OutreachChannel::Email,
            'tone' => OutreachTone::Direct,
            'locale' => SupportedLocale::English,
            'status' => OutreachMessageStatus::Draft,
            'subject' => 'A quick question about onboarding',
            'body' => "Hello,\n\nI noticed you run onboarding in-house. We cut that to two weeks "
                ."for teams your size.\n\nWorth 15 minutes?",
            'variants' => [
                ['channel' => 'email', 'body' => 'Variant one.'],
                ['channel' => 'email', 'body' => 'Variant two.'],
            ],
            'sent_to' => null,
            'provider_message_id' => null,
            'error_message' => null,
            'sent_at' => null,
            'prompt_key' => 'outreach.draft_message',
            'prompt_version' => 2,
            'model' => 'claude-test',
            'cost_micros' => 1200,
        ];
    }

    public function channel(OutreachChannel $channel): self
    {
        return $this->state(fn (): array => [
            'channel' => $channel,
            'subject' => $channel->hasSubject() ? 'A quick question about onboarding' : null,
            'variants' => [['channel' => $channel->value, 'body' => 'Variant one.']],
        ]);
    }

    public function forContact(int $contactId): self
    {
        return $this->state(fn (): array => ['lead_contact_id' => $contactId]);
    }

    public function sent(string $to = 'hello@example.com'): self
    {
        return $this->state(fn (): array => [
            'status' => OutreachMessageStatus::Sent,
            'sent_to' => $to,
            'provider_message_id' => 'test-provider-id',
            'sent_at' => now(),
        ]);
    }

    public function logged(): self
    {
        return $this->state(fn (): array => ['status' => OutreachMessageStatus::Logged]);
    }

    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => OutreachMessageStatus::Failed,
            'error_message' => 'Domain not verified.',
        ]);
    }
}
