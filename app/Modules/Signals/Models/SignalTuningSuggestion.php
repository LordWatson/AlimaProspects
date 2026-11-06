<?php

declare(strict_types=1);

namespace App\Modules\Signals\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Signals\Enums\SignalTuningKind;
use App\Modules\Signals\Enums\SignalTuningStatus;
use App\Modules\Signals\Services\SignalTuningAdvisor;
use Database\Factories\SignalTuningSuggestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A change {@see SignalTuningAdvisor} thinks the team should make, waiting for them to accept or
 * decline it (S12).
 *
 * **Nothing here applies itself**, and the table exists to make that structural rather than a
 * promise. The advisor writes rows; the settings panel shows them with their evidence; accepting
 * one is an ordinary audited edit to a definition or a watch — the same write a member could have
 * made by hand, with the same audit entry. A feature that silently retunes itself is a feature
 * nobody can debug: the member who asks "why did it stop finding these?" has no answer, and
 * neither does the person supporting them.
 *
 * `evidence` is what the advisor counted, stored so the proposal can be **checked** rather than
 * only obeyed. `payload` is the change as values the accept Action will apply — never a sentence,
 * because the wording is composed at render time for an EN/AR screen.
 *
 * `fingerprint` is a stable hash of what is being proposed, and it does two jobs: re-running the
 * advisor refreshes a live proposal rather than stacking duplicates, and a **declined** proposal is
 * recognised when the same evidence comes round again, so the panel asks once rather than every
 * week.
 *
 * @property int $id
 * @property int $team_id
 * @property SignalTuningKind $kind
 * @property int|null $signal_definition_id
 * @property array<string, mixed> $payload
 * @property array<string, mixed> $evidence
 * @property SignalTuningStatus $status
 * @property string $fingerprint
 * @property int|null $actioned_by
 * @property Carbon|null $actioned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SignalTuningSuggestion extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SignalTuningSuggestionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'kind',
        'signal_definition_id',
        'payload',
        'evidence',
        'status',
        'fingerprint',
        'actioned_by',
        'actioned_at',
    ];

    /**
     * The type this is about, when there is one and it still exists.
     *
     * `withTrashed` for the usual reason (ADR 0018 §4) — and here with an extra consequence worth
     * knowing: a proposal about a **deleted** type is still readable, which is what lets the panel
     * say "this suggestion is about a type you have since removed" instead of showing a blank row.
     *
     * @return BelongsTo<SignalDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(SignalDefinition::class, 'signal_definition_id')->withTrashed();
    }

    /**
     * Who accepted or declined it.
     *
     * @return BelongsTo<User, $this>
     */
    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }

    /**
     * A value out of the payload, as an integer, or null.
     *
     * The payload is JSON a member never typed — the advisor wrote it — but it is still read back
     * from the database into an Action that writes a threshold, so it is narrowed here rather than
     * trusted at the call site.
     */
    public function payloadInt(string $key): ?int
    {
        $value = $this->payload[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * A value out of the payload, as a non-empty string, or null.
     */
    public function payloadString(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kind' => SignalTuningKind::class,
            'status' => SignalTuningStatus::class,
            'payload' => 'array',
            'evidence' => 'array',
            'actioned_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SignalTuningSuggestionFactory
    {
        return SignalTuningSuggestionFactory::new();
    }
}
