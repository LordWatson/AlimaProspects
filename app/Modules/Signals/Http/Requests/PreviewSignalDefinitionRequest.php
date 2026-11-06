<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Requests;

use App\Modules\Signals\Data\SignalDefinitionData;
use App\Modules\Signals\Models\SignalDefinition;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The definition to preview: whatever is in the form, saved or not (S9).
 *
 * **The same rules as saving one**, from {@see SignalDefinitionData::rules()}, which is the point
 * rather than a convenience: a preview of something that could not be saved would answer a question
 * nobody asked. It also means the composer's draft, a hand-written type and a saved type being
 * re-tuned all travel the same path, so there is one thing to test and one thing to go wrong.
 *
 * No id, deliberately. A preview is about the **text in front of the member**, which may differ from
 * the row it came from by three words they have just typed — and previewing the stored version would
 * quietly answer about the wrong thing.
 */
final class PreviewSignalDefinitionRequest extends FormRequest
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
        return SignalDefinitionData::rules();
    }

    public function definition(): SignalDefinitionData
    {
        return SignalDefinitionData::fromValidated($this->validated());
    }

    /**
     * The draft as an **unsaved** model, which is all the preview needs.
     *
     * `new` and `forceFill`, never `create`: a draft that saved itself in order to be previewed
     * would put a type a member has not accepted into their library, and the whole arrangement
     * exists so the AI drafts and the member owns.
     */
    public function unsavedDefinition(): SignalDefinition
    {
        $data = $this->definition();

        $definition = new SignalDefinition;
        $definition->forceFill([
            ...$data->toAttributes(),
            'key' => SignalDefinitionData::keyFor($data->name),
        ]);

        return $definition;
    }
}
