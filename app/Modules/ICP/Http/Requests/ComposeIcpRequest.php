<?php

declare(strict_types=1);

namespace App\Modules\ICP\Http\Requests;

use App\Modules\ICP\Actions\ComposeIcpAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * The member's description of who they want to sell to, on its way to the composer.
 *
 * One field, and the only interesting thing about it is the **upper bound**. The brief reaches a
 * reasoning-tier prompt, so its length is a cost as well as an injection surface — ADR 0017's rule
 * is that a prompt input is bounded in characters by a validator *and* a truncation, because a rule
 * can be skipped by a path nobody thought about. {@see ComposeIcpAction}
 * truncates again for that reason.
 *
 * The lower bound earns its place too: "food" produces a confident, generic and useless profile,
 * and it costs the same as a good one.
 */
final class ComposeIcpRequest extends FormRequest
{
    public const int MIN_BRIEF = 20;

    public const int MAX_BRIEF = 1_000;

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
            'brief' => ['required', 'string', 'min:'.self::MIN_BRIEF, 'max:'.self::MAX_BRIEF],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'brief.min' => 'Tell Alima a little more — what you do, and what kind of business you want to reach.',
        ];
    }

    public function brief(): string
    {
        return Str::squish((string) $this->validated()['brief']);
    }
}
