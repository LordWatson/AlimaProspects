<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Core\Enums\Market;
use App\Modules\Signals\Enums\SignalWatchCadence;
use App\Modules\Signals\Http\Requests\SaveSignalWatchRequest;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalQueryBuilder;
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
 * so there is one place to change when that happens (S8).
 *
 * Since S7 the watched types are **definition ids**, not enum values (ADR 0018): a signal type is a
 * row the team owns. The ids are carried as plain integers here and resolved to the team's own rows
 * by `SaveSignalWatchAction` — a DTO that queried would be a DTO that could not be built from a
 * request.
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
     * How many types one watch may name. The real ceiling is the team's library
     * (`signals.max_definitions_per_team`); this is the shape bound, so a tampered form cannot
     * post ten thousand ids for the Action to resolve.
     */
    public const int MAX_DEFINITIONS = 50;

    /**
     * @param  list<int>  $definitionIds  The team's signal types this watch watches for.
     * @param  list<string>  $ignoreDomains  Sources this team never wants to hear from (S6).
     */
    public function __construct(
        public readonly string $name,
        public readonly string $location,
        public readonly array $definitionIds,
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
        return new self(
            name: self::text($validated['name'] ?? null, self::MAX_NAME) ?? self::defaultName(),
            location: self::text($validated['location'] ?? null, self::MAX_LOCATION) ?? self::defaultLocation(),
            definitionIds: self::ids($validated['signal_definition_ids'] ?? null),
            cadence: SignalWatchCadence::tryFrom((string) ($validated['cadence'] ?? '')) ?? SignalWatchCadence::Daily,
            // A missing checkbox is false in a form post, so "absent" has to mean the default
            // rather than being read as "switch it off".
            isActive: array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
            icpId: isset($validated['icp_id']) ? (int) $validated['icp_id'] : null,
            ignoreDomains: self::domains($validated['ignore_domains'] ?? null),
        );
    }

    /**
     * The one-click default watch for a team that has none (S5): the market's default location,
     * every type the team has, daily. Deliberately the broadest useful watch — a first watch that
     * finds nothing teaches the team that Signals doesn't work.
     *
     * The ids come from the caller rather than from here, because "every type" is now a question
     * about the team's library: the controller clones the market's presets first
     * (`CloneSignalPresetsAction`) and passes what it got.
     *
     * @param  list<int>  $definitionIds
     */
    public static function defaultWatch(array $definitionIds = []): self
    {
        return new self(
            name: self::defaultName(),
            location: self::defaultLocation(),
            definitionIds: $definitionIds,
            cadence: SignalWatchCadence::Daily,
        );
    }

    /**
     * The attributes for the model, ready to create or fill with.
     *
     * The watched types are **not** here: they live in the `signal_watch_definition` pivot, and
     * attaching them is a separate statement inside the Action's transaction (ADR 0018 §5).
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'market' => $this->market,
            'location' => $this->location,
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
     * The ids are **not** checked against the team's own rows here, and that is deliberate: an id
     * from another team is dropped and logged by the Action rather than rejected, exactly as an
     * out-of-team ICP is. It can only arrive from a tampered form, and dropping it saves a watch
     * the member had every right to save.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.self::MAX_NAME],
            'location' => ['required', 'string', 'max:'.self::MAX_LOCATION],
            'signal_definition_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_DEFINITIONS],
            'signal_definition_ids.*' => ['integer', 'distinct', 'min:1'],
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
     * A de-duplicated list of positive integer ids, bounded.
     *
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $entry) {
            if (! is_numeric($entry)) {
                continue;
            }

            $id = (int) $entry;

            if ($id > 0 && ! in_array($id, $ids, strict: true)) {
                $ids[] = $id;
            }
        }

        return array_slice($ids, 0, self::MAX_DEFINITIONS);
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
