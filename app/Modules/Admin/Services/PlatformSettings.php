<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Data\SettingDefinition;
use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Models\PlatformSetting;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;

/**
 * The operational switches, with `config/admin.php` as both the schema and the fallback
 * (Phase 19b, ADR 0015).
 *
 * The division is the design:
 *
 * - **Config declares every key** — its type, its default, its label and whether it is an
 *   operational switch. A key that is not declared cannot be read or written, which is what stops a
 *   settings table becoming a junk drawer of strings nobody can type-check.
 * - **The table holds only overrides.** A row means "an operator changed this one". With no row, the
 *   answer is config's default, so a fresh deployment behaves exactly as `.env` says.
 *
 * Why not `.env` alone: an operator has to be able to turn Stripe off at 2am from a browser, and a
 * deploy is not a control surface. Why not the table alone: see above.
 *
 * **Cached, and the cache is the whole point.** `StripeSwitch` is asked on every `BillingGateway`
 * resolution and `CapabilityGate` on every guard check, so an uncached read would put a query on
 * both hot paths. One cache entry holds the whole override set — a few rows — because reading them
 * together is one query instead of one per key, and they are written in ones.
 *
 * Every change is audited with its **before and after**. A switch that changed itself is a switch
 * nobody can ask about, and `updated_by` plus the audit entry are the two halves of answering "who
 * turned payments off, and what was it before?".
 */
final class PlatformSettings
{
    private const string CACHE_KEY = 'admin.platform_settings';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $memo = null;

    public function __construct(
        private readonly Cache $cache,
        private readonly AdminAudit $audit,
    ) {}

    /**
     * Every declared key, in declaration order.
     *
     * @return array<string, SettingDefinition>
     */
    public function definitions(): array
    {
        /** @var mixed $declared */
        $declared = config('admin.settings', []);
        $definitions = [];

        foreach (is_array($declared) ? $declared : [] as $key => $definition) {
            if (is_string($key) && is_array($definition)) {
                $definitions[$key] = SettingDefinition::fromConfig($key, $definition);
            }
        }

        return $definitions;
    }

    public function definition(string $key): ?SettingDefinition
    {
        return $this->definitions()[$key] ?? null;
    }

    /**
     * The value in force: an operator's override, or the declared default.
     *
     * **An undeclared key throws**, like `set()` does. The earlier version returned null on the
     * argument that a hard failure should not take a page down over a setting nobody uses — which
     * reads well until you notice what null does here: every caller is a literal in our own code,
     * so an undeclared key is always a typo rather than an input, and the typo is *silent and
     * consequential*. `bool('billing.stripe_enable')` returns false, which switches Stripe off;
     * `bool('announcements.enable')` returns false, which hides every announcement. Both look
     * exactly like a working screen. A page that fails loudly on a misspelling is the cheaper
     * failure by a wide margin.
     *
     * @throws AdminException
     */
    public function get(string $key): mixed
    {
        $definition = $this->definition($key);

        if (! $definition instanceof SettingDefinition) {
            throw AdminException::unknownSetting($key);
        }

        $overrides = $this->overrides();

        return array_key_exists($key, $overrides)
            ? $definition->type->cast($overrides[$key])
            : $definition->default;
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function int(string $key): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<string>
     */
    public function list(string $key): array
    {
        $value = $this->get($key);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? $item : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * Set an override, audited with the value it replaced.
     *
     * Writing the **declared default** removes the override rather than storing it, so the table
     * stays a record of deviations. A row saying "this is set to what config already says" is a row
     * that silently stops tracking config the next time somebody changes `.env`.
     *
     * @throws AdminException when the key is not declared
     */
    public function set(string $key, mixed $value, ?User $operator = null): void
    {
        $definition = $this->definition($key);

        if (! $definition instanceof SettingDefinition) {
            throw AdminException::unknownSetting($key);
        }

        $cast = $definition->type->cast($value);
        $before = $this->get($key);

        if ($cast === $before) {
            return;
        }

        DB::transaction(function () use ($definition, $key, $cast, $before, $operator): void {
            if ($cast === $definition->default) {
                PlatformSetting::query()->where('key', $key)->delete();
            } else {
                PlatformSetting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $cast, 'updated_by' => $operator?->getKey()],
                );
            }

            $this->audit->record('admin.setting.changed', null, [
                'key' => $key,
                // Both sides, because "who turned payments off" is only half the question.
                'from' => $before,
                'to' => $cast,
                'operator_id' => $operator?->getKey(),
            ]);
        });

        $this->flush();
    }

    /**
     * Every setting with its current value and where it came from — what the settings screen renders
     * and what an operator needs to see: a value alone does not say whether somebody chose it.
     *
     * @return list<array<string, mixed>>
     */
    public function describeAll(): array
    {
        $overrides = $this->overrides();

        return array_values(array_map(
            fn (SettingDefinition $definition): array => [
                ...$definition->toArray(),
                'value' => $this->get($definition->key),
                'is_overridden' => array_key_exists($definition->key, $overrides),
            ],
            $this->definitions(),
        ));
    }

    public function flush(): void
    {
        $this->memo = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * The override set, cached whole.
     *
     * Memoised per instance *and* cached, which is not belt-and-braces: the cache saves the query
     * across requests, and the memo saves the cache round-trip within one — and on a hot path asked
     * once per guard check, a Redis call is not free either.
     *
     * @return array<string, mixed>
     */
    private function overrides(): array
    {
        return $this->memo ??= $this->cache->rememberForever(
            self::CACHE_KEY,
            static fn (): array => PlatformSetting::query()
                ->pluck('value', 'key')
                ->all(),
        );
    }
}
