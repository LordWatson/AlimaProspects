<?php

declare(strict_types=1);

namespace App\Modules\Signals\Testing;

use App\Modules\Signals\Contracts\SignalSourceProvider;
use App\Modules\Signals\Data\ExtractedSignal;
use App\Modules\Signals\Data\SignalSourceQuery;
use App\Modules\Signals\Data\SourceItem;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Services\SignalItemNormaliser;
use App\Modules\Signals\Services\SignalSourceRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A configurable in-memory {@see SignalSourceProvider} for tests, so the whole Signals pipeline
 * (query builder → run → AI extraction → persistence) runs without touching Serper.
 *
 * **Registered into the {@see SignalSourceRegistry} rather than bound to a contract**, which is the
 * one thing S13 changed about how tests set this up: there is no single news client to swap any
 * more, so the fake takes the registry's place for whichever kinds a test cares about. One line does
 * it — {@see self::install()} — and a fake installed for `news` leaves a definition that also runs
 * on `web_search` searching nothing, which is usually what a test means.
 *
 * Replaces `FakeNewsSearchClient` (S13, ADR 0023).
 */
final class FakeSignalSource implements SignalSourceProvider
{
    /** @var list<SourceItem> */
    private array $items = [];

    private ?Throwable $failure = null;

    /** @var array<string, Throwable> keyed by the query text that should fail */
    private array $perQueryFailures = [];

    /** @var list<SignalSourceQuery> */
    private array $queries = [];

    private bool $supports = true;

    public function __construct(
        private readonly SignalSourceKind $kind = SignalSourceKind::News,
        private readonly SignalItemNormaliser $normaliser = new SignalItemNormaliser,
    ) {}

    /**
     * Install a fake into the real registry for the given kinds (news by default) and return it.
     *
     * Returns the **first** fake so the common single-source case reads as one assignment. Pass more
     * than one kind to have the same canned items answer for each; build separate instances when a
     * test needs the two sources to return different things.
     */
    public static function install(Application $app, SignalSourceKind ...$kinds): self
    {
        $kinds = $kinds === [] ? [SignalSourceKind::News] : $kinds;

        /** @var SignalSourceRegistry $registry */
        $registry = $app->make(SignalSourceRegistry::class);

        $first = null;

        foreach ($kinds as $kind) {
            $fake = new self($kind);
            $registry->register($fake);
            $first ??= $fake;
        }

        /** @var self $first */
        return $first;
    }

    public function kind(): SignalSourceKind
    {
        return $this->kind;
    }

    public function supports(SignalDefinition $definition): bool
    {
        return $this->supports;
    }

    /**
     * Make this source decline every definition, to exercise the registry's `supports()` filter.
     */
    public function willDecline(): self
    {
        $this->supports = false;

        return $this;
    }

    /**
     * Queue the items every fetch should return (capped to the query's `num`, as Serper would).
     *
     * @param  list<SourceItem>  $items
     */
    public function willReturn(array $items): self
    {
        $this->items = $items;

        return $this;
    }

    /**
     * Make every fetch throw (to exercise the run's failure path).
     */
    public function willFail(Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    /**
     * Make **one** query throw, leaving the rest of the plan to succeed.
     *
     * This is what a rejected query pattern looks like from the run's side (ADR 0023 §4), and it has
     * to be expressible per query: the behaviour worth testing is that the *other* queries still
     * run and the rejection lands in the run's source breakdown.
     */
    public function willFailQuery(string $q, Throwable $failure): self
    {
        $this->perQueryFailures[$q] = $failure;

        return $this;
    }

    public function fetch(SignalSourceQuery $query): array
    {
        $this->queries[] = $query;

        if (isset($this->perQueryFailures[$query->q])) {
            throw $this->perQueryFailures[$query->q];
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        // Stamped with this fake's kind, as a real provider stamps its own — otherwise a test
        // asserting `signals.source_kind` would pass against an item that never said where it came
        // from.
        return array_map(
            fn (SourceItem $item): SourceItem => $item->kind === $this->kind
                ? $item
                : new SourceItem(
                    title: $item->title,
                    snippet: $item->snippet,
                    link: $item->link,
                    urlHash: $item->urlHash,
                    source: $item->source,
                    publishedAt: $item->publishedAt,
                    raw: $item->raw,
                    contentHash: $item->contentHash,
                    dedupeHash: $item->dedupeHash,
                    kind: $this->kind,
                    matchedTemplate: $item->matchedTemplate,
                    classification: $item->classification,
                ),
            array_slice($this->items, 0, $query->num),
        );
    }

    public function estimatedCostMicros(int $queries): int
    {
        return max(0, $queries) * $this->kind->costMicrosPerRequest();
    }

    /**
     * The queries this fake was asked to run, in order.
     *
     * @return list<SignalSourceQuery>
     */
    public function queries(): array
    {
        return $this->queries;
    }

    /**
     * Build a {@see SourceItem} with the same identities a real source would give it — the URL hash,
     * the content hash and the dedupe hash (S11).
     *
     * Through the real {@see SignalItemNormaliser}, deliberately: a fixture whose hashes were
     * hand-rolled would let a redirect or clustering test pass against a rule the run does not
     * apply.
     *
     * @param  array<string, mixed>  $raw
     */
    public function item(
        string $title,
        string $link,
        string $snippet = '',
        ?string $source = null,
        ?Carbon $publishedAt = null,
        array $raw = [],
        ?ExtractedSignal $classification = null,
    ): SourceItem {
        return new SourceItem(
            title: $title,
            snippet: $snippet,
            link: $link,
            urlHash: (string) $this->normaliser->urlHash($link),
            source: $source,
            publishedAt: $publishedAt,
            raw: $raw === [] ? ['title' => $title, 'snippet' => $snippet, 'link' => $link, 'source' => $source] : $raw,
            contentHash: $this->normaliser->contentHash($title, $source),
            dedupeHash: $this->normaliser->dedupeHash($link, $title, $source),
            kind: $this->kind,
            classification: $classification,
        );
    }

    /**
     * A small set of canned genuine Dubai events, one per event type.
     *
     * @return list<SourceItem>
     */
    public function dubaiFixtures(): array
    {
        return [
            $this->item(
                title: 'Dubai startup Acme closes $40m Series A',
                link: 'https://gulfbusiness.com/acme-series-a',
                snippet: 'Dubai-based Acme DMCC has raised a $40m Series A led by regional investors.',
                source: 'Gulf Business',
                publishedAt: Carbon::parse('2 days ago')->utc(),
            ),
            $this->item(
                title: 'Regional Holdings acquires Dubai logistics firm Zephyr',
                link: 'https://arabianbusiness.com/zephyr-acquired',
                snippet: 'Regional Holdings PJSC has acquired Dubai logistics company Zephyr for an undisclosed sum.',
                source: 'Arabian Business',
                publishedAt: Carbon::parse('4 days ago')->utc(),
            ),
            $this->item(
                title: 'UK founder relocates family office to Dubai',
                link: 'https://thenationalnews.com/family-office-dubai',
                snippet: 'A prominent UK founder has opened a family office in Dubai after relocating this year.',
                source: 'The National',
                publishedAt: Carbon::parse('1 day ago')->utc(),
            ),
        ];
    }
}
