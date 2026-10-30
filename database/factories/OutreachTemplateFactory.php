<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Core\Enums\SupportedLocale;
use App\Modules\Core\Models\Team;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\OutreachTone;
use App\Modules\Outreach\Models\OutreachTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutreachTemplate>
 */
class OutreachTemplateFactory extends Factory
{
    protected $model = OutreachTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'created_by' => User::factory(),
            // Sequenced, because the name is unique per team and half the tests make two.
            'name' => 'Brief '.$this->faker->unique()->numberBetween(1, 100000),
            'channel' => OutreachChannel::Email,
            'tone' => OutreachTone::Direct,
            'locale' => SupportedLocale::English,
            'subject' => 'A quick question about :company',
            'body' => 'Lead with how long their onboarding takes. Mention our two-week setup. '
                .'Ask for 15 minutes, never for a sale.',
            'is_default' => false,
        ];
    }

    public function channel(OutreachChannel $channel): self
    {
        return $this->state(fn (): array => [
            'channel' => $channel,
            // A channel with no subject line must not carry one, or the "subject dropped" test
            // would pass on a row the factory had already made wrong.
            'subject' => $channel->hasSubject() ? 'A quick question' : null,
        ]);
    }

    public function tone(OutreachTone $tone): self
    {
        return $this->state(fn (): array => ['tone' => $tone]);
    }

    public function arabic(): self
    {
        return $this->state(fn (): array => ['locale' => SupportedLocale::Arabic]);
    }

    public function isDefault(): self
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}
