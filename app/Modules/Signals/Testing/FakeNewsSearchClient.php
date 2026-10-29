<?php

declare(strict_types=1);

namespace App\Modules\Signals\Testing;

use App\Modules\Signals\Contracts\NewsSearchClient;
use App\Modules\Signals\Data\NewsItem;
use App\Modules\Signals\Data\SignalQuery;
use App\Modules\Signals\Services\SerperNewsClient;
use App\Modules\Signals\Services\SignalItemNormaliser;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A configurable in-memory {@see NewsSearchClient} for tests, so the whole Signals pipeline (query
 * builder → run → AI extraction → persistence) runs without touching Serper. Bind it in place of
 * {@see SerperNewsClient}:
 * `$this->app->instance(NewsSearchClient::class, $fake)`.
 */
final class FakeNewsSearchClient implements NewsSearchClient
{
    /** @var list<NewsItem> */
    private array $items = [];

    private ?Throwable $failure = null;

    /** @var list<SignalQuery> */
    private array $queries = [];

    public function __construct(private readonly SignalItemNormaliser $normaliser = new SignalItemNormaliser) {}

    /**
     * Queue the items every search should return (capped to the query's `num`, as Serper would).
     *
     * @param  list<NewsItem>  $items
     */
    public function willReturn(array $items): self
    {
        $this->items = $items;

        return $this;
    }

    /**
     * Make every search throw (to exercise the run's failure path).
     */
    public function willFail(Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function search(SignalQuery $query): array
    {
        $this->queries[] = $query;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return array_slice($this->items, 0, $query->num);
    }

    /**
     * The queries this fake was asked to run, in order.
     *
     * @return list<SignalQuery>
     */
    public function queries(): array
    {
        return $this->queries;
    }

    /**
     * Build a {@see NewsItem} with a correct `url_hash`, for fixtures.
     *
     * @param  array<string, mixed>  $raw
     */
    public function item(string $title, string $link, string $snippet = '', ?string $source = null, ?Carbon $publishedAt = null, array $raw = []): NewsItem
    {
        return new NewsItem(
            title: $title,
            snippet: $snippet,
            link: $link,
            urlHash: (string) $this->normaliser->urlHash($link),
            source: $source,
            publishedAt: $publishedAt,
            raw: $raw === [] ? ['title' => $title, 'snippet' => $snippet, 'link' => $link, 'source' => $source] : $raw,
        );
    }

    /**
     * A small set of canned genuine Dubai events, one per event type.
     *
     * @return list<NewsItem>
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
