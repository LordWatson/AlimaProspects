<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Core\Enums\Market;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Enums\SignalWatchCadence;
use App\Modules\Signals\Http\Requests\SaveSignalWatchRequest;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * The validated input for creating or updating a {@see SignalWatch} (from
 * {@see SaveSignalWatchRequest}).
 *
 * `market` is **not** taken from the form. v1 Signals is UAE-only (ADR 0011): the query templates,
 * the `gl` code and the outreach posture are all per-market, and a market with none of those would
 * produce a watch that silently finds nothing. It is set from {@see self::DEFAULT_MARKET} until a
 * second market's templates exist, and the decision is recorded here rather than in a controller
 * so there is one place to change when that happens.
 */
final class SignalWatchData extends Data
{
    /** v1 is UAE-only — the only market with query templates (`config/signals.php`). */
    public const Market DEFAULT_MARKET = Market::Uae;

    private const int MAX_NAME = 120;

    private const int MAX_LOCATION = 120;

    /** A sensible ceiling on a hand-kept exclusion list — beyond this it isn't a list, it's a feed. */
    private const int MAX_IGNORE_DOMAINS = 50;

    /**
     * @param  list<SignalEventType>  $eventTypes
     * @param  list<string>  $ignoreDomains  Sources this team never wants to hear from (S6).
     */
    public function __construct(
        public readonly string $name,
        public readonly string $location,
        public readonly array $eventTypes,
        public readonly SignalWatchCadence $cadence = SignalWatchCadence::Daily,
        public readonly bool $isActive = true,
        public readonly ?int $icpId = null,
        public readonly array $ignoreDomains = [],
        public readonly Market $market = self::DEFAULT_MARKET,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $eventTypes = [];

        foreach ((array) ($validated['event_types'] ?? []) as $candidate) {
            $type = is_string($candidate) ? SignalEventType::tryFrom($candidate) : null;

            if ($type !== null && ! in_array($type, $eventTypes, strict: true)) {
                $eventTypes[] = $type;
            }
        }

        return new self(
            name: self::text($validated['name'] ?? null, self::MAX_NAME) ?? self::defaultName(),
            location: self::text($validated['location'] ?? null, self::MAX_LOCATION) ?? self::defaultLocation(),
            eventTypes: $eventTypes,
            cadence: SignalWatchCadence::tryFrom((string) ($validated['cadence'] ?? '')) ?? SignalWatchCadence::Daily,
            // A missing checkbox is false in a form post, so "absent" has to mean the default
            // rather than being read as "switch it off".
            isActive: array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
            icpId: isset($validated['icp_id']) ? (int) $validated['icp_id'] : null,
            ignoreDomains: self::domains($validated['ignore_domains'] ?? null),
        );
    }

    /**
     * The one-click default watch offered to a team with none (S5): the market's default location,
     * every event type, daily. Deliberately the broadest useful watch — a first watch that finds
     * nothing teaches the team that Signals doesn't work.
     */
    public static function defaultWatch(): self
    {
        return new self(
            name: self::defaultName(),
            location: self::defaultLocation(),
            eventTypes: SignalEventType::cases(),
            cadence: SignalWatchCadence::Daily,
        );
    }

    /**
     * The attributes for the model, ready to create or fill with.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'market' => $this->market,
            'location' => $this->location,
            'event_types' => array_map(static fn (SignalEventType $type): string => $type->value, $this->eventTypes),
            'cadence' => $this->cadence,
            'is_active' => $this->isActive,
            'icp_id' => $this->icpId,
            // Null rather than [] when empty, so "never configured" stays distinguishable from
            // "deliberately empty" (see the migration).
            'ignore_domains' => $this->ignoreDomains === [] ? null : $this->ignoreDomains,
        ];
    }

    /**
     * The validation rules a saved watch must satisfy, beside the shape they validate so the web
     * (S5) and API (S6) endpoints can't drift apart on what a watch may be.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.self::MAX_NAME],
            'location' => ['required', 'string', 'max:'.self::MAX_LOCATION],
            'event_types' => ['required', 'array', 'min:1', 'max:'.count(SignalEventType::cases())],
            'event_types.*' => ['string', 'distinct', 'in:'.implode(',', SignalEventType::values())],
            'cadence' => ['required', 'string', 'in:'.implode(',', SignalWatchCadence::values())],
            'is_active' => ['boolean'],
            'icp_id' => ['nullable', 'integer'],
            'ignore_domains' => ['nullable', 'array', 'max:'.self::MAX_IGNORE_DOMAINS],
            'ignore_domains.*' => ['string', 'max:255'],
        ];
    }

    private static function defaultName(): string
    {
        return self::defaultLocation().' wealth events';
    }

    private static function defaultLocation(): string
    {
        $market = self::DEFAULT_MARKET->value;
        $location = config("signals.markets.{$market}.default_location", 'Dubai');

        return is_string($location) && $location !== '' ? $location : 'Dubai';
    }

    /**
     * The team's exclusion list, as a de-duplicated list of trimmed strings.
     *
     * Deliberately **not** normalised to a host here: the query builder does that at the point of
     * use ({@see SignalQueryBuilder}), so a team that typed a whole URL still sees their own typing
     * when they come back to the form, rather than something the app rewrote behind them.
     *
     * @return list<string>
     */
    private static function domains(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $domains = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $domain = Str::limit(Str::squish($entry), 255, '');

            if ($domain !== '' && ! in_array($domain, $domains, strict: true)) {
                $domains[] = $domain;
            }
        }

        return array_slice($domains, 0, self::MAX_IGNORE_DOMAINS);
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $squished = Str::squish($value);

        return $squished === '' ? null : Str::limit($squished, $max, '');
    }
}
