<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Requests;

use App\Modules\Core\Support\Security\SafeUrl;
use App\Modules\Integrations\Enums\WebhookEvent;
use App\Modules\Integrations\Jobs\DeliverWebhookJob;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an endpoint a member is adding or editing (PL7).
 *
 * **The SSRF guard runs here as well as at send time**, and both are needed for different reasons.
 * Here it is a usability matter: a member typing `http://localhost:6379/` deserves "we can't reach
 * that address" beside the field, not a delivery log full of failures an hour later. At send time
 * it is the actual protection, because DNS changes after a form is submitted — which is the whole
 * of DNS rebinding, and why {@see DeliverWebhookJob} re-checks and
 * then pins.
 *
 * **https only**, which is stricter than `SafeUrl` (it allows `http` for a lead's own website). A
 * webhook body carries revealed contact details and a signature that proves it came from us; over
 * plain HTTP both are readable by anything between here and there, and there is no legitimate
 * reason for a public endpoint not to have a certificate in 2026.
 *
 * At least one event: an endpoint subscribed to nothing is an endpoint that will never fire, which
 * a member would read as broken rather than as empty.
 */
final class SaveWebhookEndpointRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', 'url:https'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookEvent::values())],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The address has to be one this application may actually reach.
     *
     * After the rules rather than as one, so a malformed URL is reported as malformed and only a
     * well-formed one that points somewhere private gets this message.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $url = $this->input('url');

            if (! is_string($url) || $validator->errors()->has('url')) {
                return;
            }

            if (! SafeUrl::allows($url)) {
                $validator->errors()->add('url', __('That address cannot be reached from the internet. Use a public https URL.'));
            }
        });
    }

    public function url(): string
    {
        return (string) $this->validated('url');
    }

    /**
     * @return list<string>
     */
    public function events(): array
    {
        /** @var list<string> $events */
        $events = $this->validated('events', []);

        // Re-ordered to the enum's own order and de-duplicated, so two endpoints subscribed to the
        // same set store the same json and a diff between them means something.
        return array_values(array_filter(
            WebhookEvent::values(),
            static fn (string $value): bool => in_array($value, $events, strict: true),
        ));
    }

    public function isActive(): bool
    {
        return (bool) $this->validated('is_active', true);
    }
}
