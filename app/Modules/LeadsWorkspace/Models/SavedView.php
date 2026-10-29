<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Models;

use App\Models\User;
use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\LeadsWorkspace\Data\LeadFilters;
use Database\Factories\SavedViewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A named set of leads-list filters a member keeps (Phase 10). Personal by default; `is_shared`
 * offers it to the rest of the team to *use*, never to edit. Tenant scoped via
 * {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property string $name
 * @property array<string, mixed> $filters
 * @property bool $is_shared
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SavedView extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<SavedViewFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'user_id',
        'name',
        'filters',
        'is_shared',
    ];

    /**
     * The member who saved the view.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The stored filters, re-read through {@see LeadFilters} so a key left behind by an older
     * release is ignored rather than breaking the list.
     */
    public function toFilters(): LeadFilters
    {
        return LeadFilters::fromArray($this->filters);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'is_shared' => 'boolean',
        ];
    }

    protected static function newFactory(): SavedViewFactory
    {
        return SavedViewFactory::new();
    }
}
