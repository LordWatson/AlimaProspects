<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Signals\Models\SignalInboundEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SignalInboundEvent>
 */
final class SignalInboundEventFactory extends Factory
{
    protected $model = SignalInboundEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = 'Acme signed a renewal worth £400k';

        return [
            'idempotency_key' => (string) Str::uuid(),
            'title' => $title,
            'summary' => 'Our CRM recorded a closed-won renewal for Acme Ltd.',
            'link' => 'https://crm.example.com/deals/1',
            'source_name' => 'Acme CRM',
            'occurred_at' => now()->subDay(),
            'received_at' => now(),
            'payload' => [
                'event_type' => 'custom_event',
                'title' => $title,
                'company_name' => 'Acme Ltd',
            ],
        ];
    }

    /** Received long enough ago to fall outside the source's window. */
    public function stale(): self
    {
        return $this->state(fn (): array => [
            'received_at' => now()->subDays(60),
        ]);
    }
}
