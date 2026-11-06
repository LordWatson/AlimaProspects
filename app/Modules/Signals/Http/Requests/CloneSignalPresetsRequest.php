<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Requests;

use App\Modules\Signals\Data\SignalDefinitionData;
use App\Modules\Signals\Services\PlatformPresetReader;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates "add these library types to my own" (S7, ADR 0018 §3).
 *
 * The keys are bounded strings and nothing more — whether a key names a live preset is
 * {@see PlatformPresetReader}'s question, and a key that has since
 * left the library produces a shorter list rather than a 422, because a form posted against last
 * month's library is the member's bad luck rather than their mistake. A request naming **no** live
 * preset is where it fails, and that comes back as `signals.unknown_preset`.
 */
final class CloneSignalPresetsRequest extends FormRequest
{
    /** One press of "add these" can't be a way to mint a hundred definitions in a single call. */
    private const int MAX_KEYS = 25;

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
            'keys' => ['required', 'array', 'min:1', 'max:'.self::MAX_KEYS],
            'keys.*' => ['string', 'distinct', 'max:'.SignalDefinitionData::MAX_KEY],
        ];
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        /** @var list<string> $keys */
        $keys = array_values(array_filter(
            (array) $this->validated('keys'),
            static fn (mixed $key): bool => is_string($key) && $key !== '',
        ));

        return $keys;
    }
}
