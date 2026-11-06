<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * The member's description of a buying moment, on its way to the composer (S9).
 *
 * One field, and the only interesting thing about it is the **upper bound**. The brief reaches a
 * reasoning-tier prompt, so its length is a cost as well as an injection surface — ADR 0017's rule
 * is that a prompt input is bounded in characters by a validator *and* a truncation, because a rule
 * can be skipped by a path nobody thought about. The Action truncates again for that reason.
 *
 * The lower bound earns its place too: a three-character brief produces a confident, generic and
 * useless draft, which costs the same as a good one.
 */
final class ComposeSignalDefinitionRequest extends FormRequest
{
    public const int MIN_BRIEF = 10;

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
            'brief' => [
                'required',
                'string',
                'min:'.self::MIN_BRIEF,
                'max:'.(int) config('signals.composer.max_brief', 1_000),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'brief.min' => 'Tell Alima a little more — one full sentence about what happens.',
        ];
    }

    public function brief(): string
    {
        return Str::squish((string) $this->validated()['brief']);
    }
}
