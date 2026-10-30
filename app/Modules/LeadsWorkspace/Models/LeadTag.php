<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\LeadSourcing\Models\Lead;
use Database\Factories\LeadTagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A team's own label for leads (Phase 10b). Created on first use, so tagging never requires setting
 * tags up first. `slug` is what uniqueness is judged on, so "Warm", "warm" and " warm " are one
 * tag. Tenant scoped via {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $leads_count
 */
class LeadTag extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<LeadTagFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'name',
        'slug',
    ];

    /**
     * The leads carrying this tag.
     *
     * @return BelongsToMany<Lead, $this>
     */
    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class, 'lead_lead_tag');
    }

    /**
     * The normalised form of a tag name: trimmed, lower-cased and space-collapsed, so the same tag
     * typed two ways is one tag. Deliberately **not** `Str::slug()`, which strips Arabic entirely
     * and would collapse every Arabic tag to an empty string.
     */
    public static function normalise(string $name): string
    {
        return Str::lower(Str::squish($name));
    }

    protected static function newFactory(): LeadTagFactory
    {
        return LeadTagFactory::new();
    }
}
