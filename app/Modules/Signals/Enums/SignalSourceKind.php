<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

use App\Modules\Signals\Contracts\SignalSourceProvider;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Services\SignalSourceRegistry;

/**
 * Where a signal came from (S13, ADR 0023).
 *
 * **An enum, while a signal _type_ is a row, and that is not a contradiction.** ADR 0018 §1 settled
 * which is which with one test — _does the application branch on the value?_ — and the answers
 * genuinely differ. A type's key is data the run loads templates and thresholds for; a **source** is
 * a class: {@see SignalSourceProvider::fetch()} for a registry filing is not a different
 * configuration of the news one, it is different code with a different credential, a different
 * parser and a different claim to be believed. A team cannot invent one.
 *
 * {@see self::isAvailable()} is what separates a case that is wired up from one that is only
 * declared, in the lineage of `SearchRegion::isOffered()` and `WatchlistSourceKind::Segment`. A case
 * with no provider behind it would be worse than no case at all, because a definition saved against
 * it would search nowhere and complete successfully.
 *
 * The {@see SignalSourceRegistry} resolves a provider by one of these;
 * `signal_definitions.source_keys` says which a {@see SignalDefinition} runs on, and
 * `signals.source_kind` records which one found a given signal.
 */
enum SignalSourceKind: string
{
    /**
     * Serper `/news` — ADR 0011's original and only source, unchanged in behaviour.
     *
     * Every definition that existed before S13 runs on this and nothing else, which is what the
     * migration's backfill to `["news"]` says.
     */
    case News = 'news';

    /**
     * Serper `/search` — the general web, for events that never reach a news desk (S13a).
     *
     * A different noise profile rather than a wider one: the spike found the social networks
     * dominate it (already on the global skip list) and that job aggregators and informational
     * service pages are what the global list misses. Hence its own defaults in
     * `signals.sources.web_search.ignore_domains`.
     */
    case WebSearch = 'web_search';

    /**
     * Companies House filings (S13b) — officer appointments and resignations, PSC changes,
     * insolvency, charges, share capital.
     *
     * **The one source whose items need no AI at all** (ADR 0023 §6): the registry has already
     * parsed the form, so `description_values` hands over the officer's name and the date and
     * `transaction_id` is a stable identity to dedupe on. Every item carries its own
     * `ExtractedSignal` and skips the extraction batch. Free, and the highest-confidence source in
     * the product.
     *
     * **It only runs on a watchlist watch**, and that is a spike finding rather than a limitation
     * somebody chose: there is no cross-company filings endpoint, so filings can only be polled per
     * company, by number. A topical watch has no entities and therefore gets no Companies House
     * queries — see `CompaniesHouseSignalSource` and the spike table in `docs/modules/signals.md`.
     */
    case CompaniesHouse = 'companies_house';

    /**
     * An RSS/Atom feed a team pastes in (S13b).
     *
     * **The opposite of a search source.** The two Serper sources look for what Google indexed; a
     * feed is a publication a team already trusts, read in full. For a niche sector that is often
     * better than any search, because the newsletter nobody else reads is the one a competitor is
     * not watching.
     *
     * Its entries are **not** structured: a feed entry is prose somebody wrote, so it is classified
     * by the same prompt as a news article. Only an authoritative, already-parsed source may assert
     * its own event (ADR 0023 §6).
     *
     * It is the one source whose **address a customer supplies**, so it is the one with an SSRF
     * surface: `Core\Support\Security\SafeUrl`, no redirects followed, a conditional GET, a byte
     * cap, a poll-interval floor per feed URL, and a per-team feed count on the save path.
     */
    case Feed = 'feed';

    /**
     * An event a customer pushed to `POST /api/v1/signal-events` (S13b).
     *
     * **Declared, not offered.** The only path into this module that originates outside our own
     * code, and ADR 0023 §7 is the whole trust model: signed, replay-protected, bounded,
     * rate-limited, wrapped in `UntrustedContent`, and never believed about a score, a jurisdiction
     * or a lead.
     */
    case Webhook = 'webhook';

    /** The default every pre-S13 definition and signal belongs to. */
    public static function default(): self
    {
        return self::News;
    }

    public function label(): string
    {
        return match ($this) {
            self::News => 'News',
            self::WebSearch => 'Web search',
            self::CompaniesHouse => 'Companies House',
            self::Feed => 'A feed you choose',
            self::Webhook => 'Your own events',
        };
    }

    /**
     * What this source is good for, for the member choosing between them on the type form.
     *
     * Written as a trade-off rather than a boast, because the honest answer to "should I add web
     * search?" is "it finds more and more of it is noise", and a member who learns that from their
     * feed instead of from the form will switch it off and not come back.
     */
    public function description(): string
    {
        return match ($this) {
            self::News => 'Press coverage. The most reliable source, and the one every signal type starts with.',
            self::WebSearch => 'The wider web — planning decisions, award announcements, a company’s own blog. Finds events the press missed, and finds more noise with them.',
            self::CompaniesHouse => 'UK filings for the accounts on a watchlist: officer and ownership changes, insolvency, charges. Structured and certain, so nothing is guessed — and free.',
            self::Feed => 'An industry newsletter, a regulator’s filings feed, a portal’s press releases. Everything it publishes is read, so a focused feed beats a broad one.',
            self::Webhook => 'Events your own systems push to Alima.',
        };
    }

    /**
     * Whether a provider is registered for this kind today.
     *
     * Checked by the save path and by the form, so a definition can never be stored against a source
     * that would silently contribute no queries. The run asks the registry instead — by then the
     * question is "is one registered", and a stored key that stopped being available (a source
     * withdrawn, a credential pulled) must be skipped rather than crash a scheduled watch.
     */
    public function isAvailable(): bool
    {
        return match ($this) {
            self::News, self::WebSearch, self::CompaniesHouse, self::Feed => true,
            self::Webhook => false,
        };
    }

    /**
     * Whether this source fetches by running keyword queries.
     *
     * The Serper family does; a feed, a filing poll and a webhook do not, which is why the per-run
     * query ceiling and the per-query cost only mean anything for these two.
     */
    public function isQueryBased(): bool
    {
        return match ($this) {
            self::News, self::WebSearch => true,
            self::CompaniesHouse, self::Feed, self::Webhook => false,
        };
    }

    /**
     * Whether a definition has to configure this source before it does anything (S13b).
     *
     * A feed needs a URL; news and web search need only the templates the type already has. The
     * form asks for the extra field when this is true, rather than letting somebody tick a source
     * that will quietly contribute nothing.
     */
    public function needsConfiguration(): bool
    {
        return $this === self::Feed;
    }

    /**
     * Whether items from this source arrive already classified (ADR 0023 §6).
     *
     * Only a source **we** wrote, against an authoritative provider, may say true — never one whose
     * content came from free text, and never the webhook.
     */
    public function isStructured(): bool
    {
        return $this === self::CompaniesHouse;
    }

    /**
     * The cost, in micro-units, of one request to this source.
     *
     * Read from `signals.sources.<key>.cost_micros_per_request`, falling back to the module-wide
     * `signals.cost_micros_per_request` so the news figure keeps its existing meaning and its
     * existing env key. A free source configures zero.
     */
    public function costMicrosPerRequest(): int
    {
        return (int) config(
            'signals.sources.'.$this->value.'.cost_micros_per_request',
            config('signals.cost_micros_per_request', 1_000),
        );
    }

    /**
     * This source's **own** skip list, merged on top of the global one for this source only.
     *
     * @return list<string>
     */
    public function ignoreDomains(): array
    {
        $configured = config('signals.sources.'.$this->value.'.ignore_domains', []);

        $domains = [];

        // Filtered rather than trusted, because this reaches a host comparison: a non-string or an
        // empty entry in the config would be compared against every result's host, and an empty
        // string ends every string.
        foreach (is_array($configured) ? $configured : [] as $domain) {
            if (is_string($domain) && trim($domain) !== '') {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }

    /**
     * The kinds a member may choose from today, news first.
     *
     * News leads because it is the answer for almost everybody and it is what a type starts with.
     *
     * @return list<self>
     */
    public static function available(): array
    {
        $order = [self::News, self::WebSearch, self::CompaniesHouse, self::Feed, self::Webhook];

        return array_values(array_filter($order, static fn (self $kind): bool => $kind->isAvailable()));
    }

    /**
     * @return list<string>
     */
    public static function availableValues(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::available());
    }

    /**
     * Read a stored key, or null. Never throws: a column holding a key this build does not know
     * (a source withdrawn between deploys) must be skipped, not fatal.
     */
    public static function parse(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * Read a stored list of keys into the kinds this build can actually run.
     *
     * Falls back to {@see self::default()} when nothing readable survives — the ADR 0023 §2 rule:
     * a definition that searches nowhere is a worse outcome than one that searches the news.
     *
     * @return list<self>
     */
    public static function parseList(mixed $values): array
    {
        $kinds = [];

        foreach (is_array($values) ? $values : [] as $value) {
            $kind = self::parse($value);

            if ($kind instanceof self && ! in_array($kind, $kinds, strict: true)) {
                $kinds[] = $kind;
            }
        }

        return $kinds === [] ? [self::default()] : $kinds;
    }
}
