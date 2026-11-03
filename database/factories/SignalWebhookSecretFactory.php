<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Signals\Models\SignalWebhookSecret;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalWebhookSecret>
 */
final class SignalWebhookSecretFactory extends Factory
{
    protected $model = SignalWebhookSecret::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Through the model's own minting, so a test signs with a secret shaped exactly like a real
        // one — and so the hint is derived the same way rather than invented here.
        return SignalWebhookSecret::mint();
    }

    /** A known secret, for a test that needs to sign a request with it. */
    public function withSecret(string $secret): self
    {
        return $this->state(fn (): array => [
            'secret' => $secret,
            'hint' => substr($secret, -8),
        ]);
    }
}
