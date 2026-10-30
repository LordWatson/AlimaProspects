<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Http\Requests;

use App\Modules\Scoring\Enums\FeedbackRating;
use App\Modules\Scoring\Policies\LeadScorePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a verdict on a lead score: the rating, plus an optional short note. Authorisation is
 * handled in the controller via {@see LeadScorePolicy}.
 */
final class RecordScoreFeedbackRequest extends FormRequest
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
            'rating' => ['required', Rule::enum(FeedbackRating::class)],
            'comment' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function rating(): FeedbackRating
    {
        return FeedbackRating::from((string) $this->validated('rating'));
    }

    public function comment(): ?string
    {
        $comment = $this->validated('comment');

        return is_string($comment) && trim($comment) !== '' ? trim($comment) : null;
    }
}
