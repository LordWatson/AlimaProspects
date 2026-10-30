<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Models;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Enums\Currency;
use App\Modules\Core\Enums\Market;
use App\Modules\Onboarding\Enums\CompanySize;
use Database\Factories\BusinessProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A team's self-description — the canonical, business-authored profile captured during onboarding
 * and read by Business Intelligence, ICP, Scoring and Outreach. One row per team (MVP). Tenant
 * scoped via {@see BelongsToTeam}.
 *
 * @property int $id
 * @property int $team_id
 * @property string|null $company_name
 * @property string|null $website
 * @property string|null $industry
 * @property CompanySize|null $company_size
 * @property list<string>|null $locations
 * @property list<string>|null $regions_served
 * @property Market|null $primary_market
 * @property list<string>|null $products_services
 * @property string|null $value_proposition
 * @property int|null $pricing_min minor units
 * @property int|null $pricing_max minor units
 * @property Currency|null $pricing_currency
 * @property list<string>|null $customer_examples
 * @property int $version
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BusinessProfile extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<BusinessProfileFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'company_name',
        'website',
        'industry',
        'company_size',
        'locations',
        'regions_served',
        'primary_market',
        'products_services',
        'value_proposition',
        'pricing_min',
        'pricing_max',
        'pricing_currency',
        'customer_examples',
    ];

    /**
     * Whether onboarding has been completed for this profile.
     */
    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'company_size' => CompanySize::class,
            'primary_market' => Market::class,
            'pricing_currency' => Currency::class,
            'locations' => 'array',
            'regions_served' => 'array',
            'products_services' => 'array',
            'customer_examples' => 'array',
            'pricing_min' => 'integer',
            'pricing_max' => 'integer',
            'version' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): BusinessProfileFactory
    {
        return BusinessProfileFactory::new();
    }
}
