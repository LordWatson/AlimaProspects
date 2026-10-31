<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Models\User;
use App\Modules\Admin\Enums\AnnouncementLevel;
use App\Modules\Core\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Something the platform wants to tell its tenants (Phase 19b).
 *
 * **Bilingual, unlike the admin panel that writes it.** The panel is internal and English by
 * decision; an announcement is read by customers, and half of them are in the UAE (ADR 0007). So
 * `title` and `body` are per-locale maps and {@see self::copyFor()} falls back to English rather
 * than rendering nothing — a maintenance notice in no language is worse than one in the wrong one.
 *
 * Platform-wide, so no `BelongsToTeam`: it is ours, and `plans`/`markets` are how it is *targeted*
 * at tenants rather than owned by one.
 *
 * @property int $id
 * @property AnnouncementLevel $level
 * @property array<string, string> $title
 * @property array<string, string> $body
 * @property list<string>|null $plans
 * @property list<string>|null $markets
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_dismissible
 * @property bool $notify
 * @property Carbon|null $notified_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Announcement extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'level',
        'title',
        'body',
        'plans',
        'markets',
        'starts_at',
        'ends_at',
        'is_dismissible',
        'notify',
        'created_by',
    ];

    /**
     * The announcements live at a moment — what every tenant page asks for.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeLive(Builder $query, ?CarbonImmutable $at = null): Builder
    {
        $at ??= CarbonImmutable::now('UTC');

        return $query
            ->where('starts_at', '<=', $at)
            ->where(static fn (Builder $nested): Builder => $nested
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>', $at))
            ->orderByDesc('starts_at');
    }

    /**
     * Whether this announcement is aimed at a given tenant.
     *
     * Null targets mean **everybody**, which is the honest default for maintenance and outages. A
     * plan list is matched against the plan whose entitlements are in force rather than the one the
     * team is paying for, because a trialling team on Team-plan entitlements should read a Team-plan
     * notice.
     */
    public function targets(Team $team, string $planKey): bool
    {
        if (is_array($this->plans) && $this->plans !== [] && ! in_array($planKey, $this->plans, true)) {
            return false;
        }

        if (is_array($this->markets) && $this->markets !== [] && ! in_array($team->market, $this->markets, true)) {
            return false;
        }

        return true;
    }

    /**
     * The title and body in a reader's language, falling back to English and then to whatever is
     * there.
     *
     * The second fallback matters: an operator who filled in Arabic and not English should not
     * leave Arabic readers with a blank banner because the English key is the one we look for
     * first.
     *
     * @return array{title: string, body: string, title_locale: string, body_locale: string}
     */
    public function copyFor(string $locale): array
    {
        $title = $this->pick($this->title, $locale);
        $body = $this->pick($this->body, $locale);

        return [
            'title' => $title['value'],
            'body' => $body['value'],
            // Per field, because an operator who filled in an Arabic title and only an English body
            // produces a banner with one of each — and each line has to be marked for what it is.
            'title_locale' => $title['locale'],
            'body_locale' => $body['locale'],
        ];
    }

    /**
     * Who has dismissed it. Per **person**, not per team: one colleague clearing a banner should
     * not hide it from the rest of their team, who have not read it.
     *
     * @return BelongsToMany<User, $this>
     */
    public function dismissedBy(): BelongsToMany
    {
        // `withPivot` and not `withTimestamps`: a dismissal happens once and never changes, so the
        // table has only `created_at` — and `withTimestamps('created_at', null)` does **not** mean
        // "no updated_at". Laravel reads the null as "unspecified" and falls back to the parent
        // model's own column, so the insert names a column that does not exist and every dismissal
        // 500s. Stamped at the call site instead.
        return $this->belongsToMany(User::class, 'announcement_dismissals')
            ->withPivot('created_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  array<string, string>|null  $map
     */
    /**
     * The copy, **and the locale it actually came from**.
     *
     * The second half is not bookkeeping. The fallbacks mean an Arabic reader can be handed an
     * English sentence, and an English sentence rendered inside an RTL page right-aligns with its
     * punctuation in the wrong place unless the element carries its own `lang` and `dir`. The
     * banner cannot work that out from the page locale, because the page locale is exactly what
     * turned out to be unavailable — so it is reported per field.
     *
     * @param  array<string, string>|null  $map
     * @return array{value: string, locale: string}
     */
    private function pick(?array $map, string $locale): array
    {
        if ($map === null) {
            return ['value' => '', 'locale' => $locale];
        }

        foreach ([$locale, 'en'] as $try) {
            $value = $map[$try] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return ['value' => trim($value), 'locale' => $try];
            }
        }

        foreach ($map as $try => $value) {
            if (trim($value) !== '') {
                return ['value' => trim($value), 'locale' => (string) $try];
            }
        }

        return ['value' => '', 'locale' => $locale];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => AnnouncementLevel::class,
            'title' => 'array',
            'body' => 'array',
            'plans' => 'array',
            'markets' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'notified_at' => 'datetime',
            'is_dismissible' => 'boolean',
            'notify' => 'boolean',
        ];
    }
}
