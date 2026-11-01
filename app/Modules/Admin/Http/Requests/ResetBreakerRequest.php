<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Enums\PlatformProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Which provider's circuit breaker to close (Phase 19c).
 *
 * The provider is enum-validated here and the *existence of a breaker* is checked in the Action —
 * three providers have none, and that is a domain fact with its own refusal
 * (`AdminException::providerNotInstrumented()`) rather than a validation rule. A rule here would
 * have made "Stripe has no breaker" read as a malformed form.
 */
final class ResetBreakerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::enum(PlatformProvider::class)],
        ];
    }

    public function provider(): PlatformProvider
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return PlatformProvider::from((string) $validated['provider']);
    }
}
