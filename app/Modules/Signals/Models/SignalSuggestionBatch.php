<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Signals\Enums\SignalSuggestionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One request for suggested signal types, and what came back (Signals v3 P2).
 *
 * **Nothing here is a signal type.** Each suggestion is a draft the member reviews and saves
 * through the ordinary form, or a library type they add themselves; this row is what the screen
 * reads while they decide, and the ledger the daily limit is counted over.
 *
 * `suggestions` is a list of `{title, rationale, preset_key, definition, state, preview}`:
 * `state` is one of the `STATE_*` constants, `definition` is a validated definition form (null for
 * a library type), and `preview` is counts and a sentence, never an article.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $requested_by
 * @property SignalSuggestionStatus $status
 * @property list<array<string, mixed>>|null $suggestions
 * @property string|null $error_code
 * @property int $cost_micros
 * @property int|null $prompt_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SignalSuggestionBatch extends Model
{
    use BelongsToTeam;

    /** Its sample search has been queued and has not reported back. */
    public const string STATE_TESTING = 'testing';

    /** A sample search ran; `preview` says what it found. */
    public const string STATE_TESTED = 'tested';

    /** No sample search ran: the day's preview allowance was spent, or the search failed. */
    public const string STATE_UNTESTED = 'untested';

    protected $fillable = [
        'team_id',
        'requested_by',
        'status',
        'suggestions',
        'error_code',
        'cost_micros',
        'prompt_version',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SignalSuggestionStatus::class,
            'suggestions' => 'array',
            'cost_micros' => 'integer',
            'prompt_version' => 'integer',
        ];
    }
}
