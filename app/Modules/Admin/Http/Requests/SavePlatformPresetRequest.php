<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Signals\Data\SignalDefinitionData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Creating or changing a platform preset (S15 task 4).
 *
 * **The rules are Signals' own** ({@see SignalDefinitionData::rules()}), which is the point: a
 * preset is a `signal_definitions` row, and a panel that validated it differently from the tenant
 * form would let an operator put something into the library that no team's own editor would accept.
 * The one addition is `key`, which a tenant never supplies — their key is derived from the name —
 * and which an operator must, because it is the identity every clone and every seed migration
 * refers to.
 *
 * `key` is only read on create. A preset's key never changes: clones point at it (`preset_key`),
 * signals record it, and the catalogue's frozen seed migrations name it, so renaming would orphan
 * all three silently. The save Action drops it on update rather than refusing, so a form that posts
 * the whole object does not fail on a field it cannot change.
 */
final class SavePlatformPresetRequest extends FormRequest
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
            ...SignalDefinitionData::rules(),
            'key' => ['required_without:id', 'nullable', 'string', 'max:48', 'regex:/^[a-z0-9_]+$/'],
        ];
    }

    public function definition(): SignalDefinitionData
    {
        return SignalDefinitionData::fromValidated($this->validated());
    }

    /**
     * The key for a new preset. Slugged here as well as in the Action, so a rejected shape is a
     * validation message rather than a silently different key.
     */
    public function key(): string
    {
        $key = $this->validated('key');

        return is_string($key) && trim($key) !== ''
            ? Str::limit(Str::slug(Str::squish($key), '_'), 48, '')
            : '';
    }
}
