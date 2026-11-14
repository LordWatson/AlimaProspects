<?php

declare(strict_types=1);

namespace App\Modules\ICP\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Enums\IcpSuggestionAction;
use App\Modules\ICP\Enums\IcpSuggestionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One change Alima proposes to an ideal customer profile. Never applied by the system: a member
 * accepts it (an ordinary audited profile update) or declines it (kept, so it is not raised again).
 * Tenant scoped via {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property int $icp_id
 * @property IcpSuggestionAction $action
 * @property CriterionType $type
 * @property string $value
 * @property int|null $weight
 * @property string $rationale
 * @property IcpSuggestionStatus $status
 * @property string $fingerprint
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property int|null $prompt_version
 * @property-read Icp $icp
 */
class IcpSuggestion extends Model
{
    use BelongsToTeam;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'icp_id',
        'action',
        'type',
        'value',
        'weight',
        'rationale',
        'status',
        'fingerprint',
        'prompt_version',
    ];

    /**
     * The identity of an idea, whatever its wording or weight: "add industry: Craft Breweries" and
     * "add industry: craft breweries" are the same suggestion, and a member who declined one has
     * declined the other.
     */
    public static function fingerprintFor(IcpSuggestionAction $action, CriterionType $type, string $value): string
    {
        return sha1($action->value.'|'.$type->value.'|'.Str::lower(Str::squish($value)));
    }

    /**
     * @return BelongsTo<Icp, $this>
     */
    public function icp(): BelongsTo
    {
        return $this->belongsTo(Icp::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'action' => IcpSuggestionAction::class,
            'type' => CriterionType::class,
            'status' => IcpSuggestionStatus::class,
            'weight' => 'integer',
            'decided_at' => 'datetime',
        ];
    }
}
