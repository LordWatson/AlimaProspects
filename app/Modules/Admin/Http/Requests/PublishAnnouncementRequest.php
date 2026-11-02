<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Enums\AnnouncementLevel;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Enums\SupportedLocale;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Publishing an announcement (Phase 19b).
 *
 * **English is required; Arabic is not.** The asymmetry is deliberate rather than lazy: English is
 * the fallback every reader gets when their own translation is missing, so an announcement without
 * it could render blank for somebody. Requiring Arabic too would mean an operator with an urgent
 * outage notice either waits for a translation or does not post it — and a maintenance notice in
 * one language beats silence in two.
 */
final class PublishAnnouncementRequest extends FormRequest
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
            'level' => ['required', Rule::enum(AnnouncementLevel::class)],

            'title.en' => ['required', 'string', 'max:160'],
            'body.en' => ['required', 'string', 'max:2000'],
            'title.ar' => ['nullable', 'string', 'max:160'],
            'body.ar' => ['nullable', 'string', 'max:2000'],

            // Null = everybody, which is the right default for maintenance and outages.
            'plans' => ['nullable', 'array'],
            'plans.*' => [Rule::enum(PlanKey::class)],
            'markets' => ['nullable', 'array'],
            'markets.*' => [Rule::enum(Market::class)],

            'starts_at' => ['nullable', 'date'],
            'ends_at' => [
                'nullable',
                'date',
                // `after:starts_at` only when there is a `starts_at` to be after. Absent, the
                // Action defaults the start to now, so `after:now` is the same rule stated against
                // the value that will actually be used.
                'after:'.($this->filled('starts_at') ? 'starts_at' : 'now'),
            ],
            'is_dismissible' => ['nullable', 'boolean'],
            'notify' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.en.required' => 'English is the fallback every reader gets, so it cannot be blank.',
            'body.en.required' => 'English is the fallback every reader gets, so it cannot be blank.',
        ];
    }

    /**
     * The announcement's columns, assembled from validated input.
     *
     * **Not `attributes()`.** That name is one of `FormRequest`'s own hooks — the validator calls it
     * for human-readable field names while building messages, which happens *before* `validated()`
     * is available. Overriding it meant every failed validation died with "Call to a member
     * function validated() on null" instead of rendering the error bag, and the screen looked
     * broken precisely when an operator had made a typo.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();

        $level = AnnouncementLevel::from((string) $v['level']);

        return [
            'level' => $level->value,
            'title' => $this->copy('title'),
            'body' => $this->copy('body'),
            'plans' => $this->targets('plans'),
            'markets' => $this->targets('markets'),
            'starts_at' => $this->instant($v['starts_at'] ?? null) ?? CarbonImmutable::now('UTC'),
            'ends_at' => $this->instant($v['ends_at'] ?? null),
            // The level's own default unless the operator said otherwise, so a critical notice is
            // not dismissible by accident.
            'is_dismissible' => $this->has('is_dismissible')
                ? $this->boolean('is_dismissible')
                : $level->defaultDismissible(),
            'notify' => $this->boolean('notify'),
        ];
    }

    /**
     * Per-locale copy, with empty translations dropped rather than stored as empty strings — an
     * empty `ar` would otherwise beat the English fallback.
     *
     * @return array<string, string>
     */
    private function copy(string $field): array
    {
        $copy = [];

        foreach (SupportedLocale::cases() as $locale) {
            $value = $this->input($field.'.'.$locale->value);

            if (is_string($value) && trim($value) !== '') {
                $copy[$locale->value] = trim($value);
            }
        }

        return $copy;
    }

    /**
     * @return list<string>|null
     */
    private function targets(string $field): ?array
    {
        $values = $this->validated($field);

        if (! is_array($values) || $values === []) {
            return null;
        }

        return array_values(array_map(strval(...), $values));
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }
}
