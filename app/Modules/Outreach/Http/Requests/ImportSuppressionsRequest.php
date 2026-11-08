<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Http\Requests;

use App\Modules\Outreach\Actions\ImportSuppressionsAction;
use App\Modules\Outreach\Enums\SuppressionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Validates a bulk load of a team's own do-not-contact list (PL5).
 *
 * **Either a pasted block or a file, and one of them is required** — the rule is written as a pair
 * of `required_without`s rather than as two endpoints, because they are the same request with the
 * text arriving by a different route, and the Action takes text either way.
 *
 * The file is bounded in **kilobytes** and restricted to plain text, which is the only check worth
 * making on it: there is no parsing to protect, nothing is stored, and a `.xlsx` renamed to `.csv`
 * simply yields no usable values and is reported as skipped. A MIME allow-list beyond text would be
 * precision about a format this never reads.
 *
 * No `scope` field, for the reason {@see SuppressIdentityRequest} gives: a member cannot write a
 * global entry from the product, so offering the field would mean validating a request that must
 * never be honoured.
 */
final class ImportSuppressionsRequest extends FormRequest
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
            'type' => ['required', Rule::in(SuppressionType::values())],
            // Generous: a pasted column of two thousand email addresses is about 60 KB, and the
            // Action's own `max_import` cap is what really bounds the work.
            'values' => ['nullable', 'required_without:file', 'string', 'max:200000'],
            'file' => ['nullable', 'required_without:values', 'file', 'mimetypes:text/plain,text/csv,application/csv', 'max:2048'],
            'note' => ['nullable', 'string', 'max:180'],
        ];
    }

    public function type(): SuppressionType
    {
        return SuppressionType::from((string) $this->validated('type'));
    }

    /**
     * The text to read, from whichever half of the request carried it.
     *
     * The upload is read into memory rather than streamed because it is capped at 2 MB and read
     * once. It is never written anywhere: {@see ImportSuppressionsAction}
     * stores hashes, and a temporary copy of somebody's client list is exactly the thing this
     * feature exists to avoid keeping.
     */
    public function values(): string
    {
        $file = $this->file('file');

        if ($file instanceof UploadedFile) {
            return (string) file_get_contents($file->getRealPath());
        }

        return (string) $this->validated('values');
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
