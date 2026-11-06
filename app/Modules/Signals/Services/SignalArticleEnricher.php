<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Exceptions\AiPlatformException;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\Core\Contracts\WebContentFetcher;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\Core\Support\RobotsPolicy;
use App\Modules\Signals\Data\ExtractedSignal;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the **whole article** for the signals at the top of the feed, and re-extracts what the
 * snippet could only guess at (S12).
 *
 * Snippet-only extraction is the oldest limitation in this module and the one most likely to be
 * worth fixing: an amount, a counterparty and a job title are all routinely in the third paragraph
 * and never in the search result. So for a signal the scorer already rates highly — the only ones
 * a member is actually going to read — this fetches the page and asks the model again.
 *
 * **It reuses, and that is most of the design:**
 *
 * - The fetch goes through `Core\Contracts\WebContentFetcher`, the SSRF-safe seam Enrichment and
 *   Business Intelligence already use. The plan said not to add an HTTP client and it was right:
 *   a second fetcher would be a second place for the host checks, the byte caps and the timeouts to
 *   be slightly different.
 * - The re-extraction reuses the **existing** `signals.extract_event` prompt with the article text
 *   in place of the snippet, rather than adding a prompt that returns the same five fields. One
 *   prompt means one schema, one set of clamps, and one place where `UntrustedContent` wraps a
 *   publisher's prose.
 * - The breaker is the module's own (`signals:cb`), so a publisher timing out repeatedly stops
 *   being tried without affecting the news provider's breaker or anybody else's.
 *
 * **Five things bound it**, because this is the only part of Signals that fetches arbitrary pages:
 * the feature flag (off by default), a heat floor, a per-run count, a per-team monthly cost ceiling,
 * and `robots.txt` ({@see RobotsPolicy}).
 *
 * **It stores the extracted fields and a timestamp, and never the article body.** Retention and
 * DSAR both get harder the moment the product keeps prose — and a signal is supposed to hold a
 * name, a role and a URL (ADR 0011). The body is read, used, and dropped.
 *
 * Nothing it can do fails a run. A page that will not load, a publisher that says no, a model that
 * returns nonsense: the signal keeps the snippet-derived fields it already had, which is exactly
 * what every signal had before this phase.
 */
final class SignalArticleEnricher
{
    private const string BREAKER_KEY = 'signals_article_fetch';

    /** Shortest page worth asking a model about. Below this the fetcher found a cookie banner. */
    private const int MIN_USEFUL_CHARS = 400;

    public function __construct(
        private readonly WebContentFetcher $fetcher,
        private readonly RobotsPolicy $robots,
        private readonly AiClient $ai,
        private readonly CircuitBreaker $breaker,
        private readonly SignalScorer $scorer,
        private readonly SignalSpendGuard $guard,
    ) {}

    /**
     * Read the articles behind this run's hottest signals, and return what it cost in micro-units.
     *
     * Called **after** the persistence transaction, never inside one: this makes HTTP calls and an
     * AI call, and CLAUDE.md's rule about external work in transactions is absolute. The signals
     * are already saved and already scored, so the worst case of everything here failing is the
     * product behaving exactly as it did in S11.
     */
    public function enrich(SignalRun $run, int $teamId): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $candidates = $this->candidates($run, $teamId);

        if ($candidates === []) {
            return 0;
        }

        $perFetch = max(0, (int) config('signals.article_fetch.cost_micros_per_fetch', 2_000));
        $remaining = $this->remainingBudget($teamId, $perFetch);
        $spent = 0;
        $read = 0;

        foreach ($candidates as $signal) {
            if ($remaining !== null && $spent + $perFetch > $remaining) {
                // The ceiling is a ceiling, not a warning. The run carries on and the rest of the
                // feed is snippet-only, which is what it would have been anyway.
                Log::channel('signals')->info('Article fetch stopped at the team’s monthly ceiling.', [
                    'team_id' => $teamId,
                    'signal_run_id' => (int) $run->getKey(),
                    'read' => $read,
                ]);

                break;
            }

            try {
                $fetched = $this->enrichOne($signal, $teamId);
            } catch (AiCreditExceededException $e) {
                // **Stop the loop.** The first version carried on and fetched every remaining
                // candidate, paying for a page each time to meet the same refusal — the one
                // failure mode where continuing costs real money for a guaranteed nothing (found
                // by `laravel-module-reviewer`).
                Log::channel('signals')->info('Article fetch stopped: the team is out of AI credit.', [
                    'team_id' => $teamId,
                    'signal_run_id' => (int) $run->getKey(),
                    'read' => $read,
                ]);

                break;
            } catch (Throwable $e) {
                // One article's failure is one article. The signals are already saved and already
                // scored, so the worst case here is the product behaving exactly as it did in S11.
                Log::channel('signals')->warning('Could not enrich a signal from its article.', [
                    'team_id' => $teamId,
                    'signal_id' => (int) $signal->getKey(),
                    'error' => $e::class,
                ]);

                continue;
            }

            if (! $fetched) {
                continue;
            }

            $spent += $perFetch;
            $read++;
        }

        if ($read > 0) {
            Log::channel('signals')->info('Read full articles for the top of the feed.', [
                'team_id' => $teamId,
                'signal_run_id' => (int) $run->getKey(),
                'read' => $read,
                'cost_micros' => $spent,
            ]);
        }

        return $spent;
    }

    /**
     * Fetch and re-extract one signal, returning whether it was actually read.
     *
     * Every failure path returns false and leaves the signal alone, except AI-credit exhaustion,
     * which is re-thrown so the caller can stop the loop. The breaker is only tripped by a
     * **fetch** failure, not by a model one: a publisher that will not load is a reason to stop
     * fetching, and an unhelpful model response is not.
     */
    private function enrichOne(Signal $signal, int $teamId): bool
    {
        if (! $this->breaker->allows(self::BREAKER_KEY)) {
            return false;
        }

        if (! $this->robots->allows($signal->source_url)) {
            // Not an error and not worth a breaker trip: the publisher has told us no, and the
            // answer is cached per host so we will not ask again today.
            Log::channel('signals')->info('Skipped an article: robots.txt disallows it.', [
                'team_id' => $teamId,
                'signal_id' => (int) $signal->getKey(),
            ]);

            return false;
        }

        $text = $this->fetcher->fetch($signal->source_url);

        if (trim($text) === '') {
            // **An empty body is how a failure reaches us**, and the breaker has to be told. The
            // shared fetcher swallows every error and returns `''`, so the `catch` the first
            // version relied on could never fire and a host that always timed out was retried on
            // every run for ever (found by `laravel-module-reviewer`). The cost of being wrong
            // here is small in the other direction: a genuinely empty page counts against a
            // publisher that is not serving us anything useful anyway.
            $this->breaker->recordFailure(self::BREAKER_KEY);

            return false;
        }

        if (mb_strlen(trim($text)) < self::MIN_USEFUL_CHARS) {
            // Short but not empty: a consent wall or a paywall stub. The host answered, so this is
            // not a transport failure and not the breaker's business — it is simply not worth an
            // AI call.
            return false;
        }

        $this->breaker->recordSuccess(self::BREAKER_KEY);

        $extracted = $this->reextract($signal, $text, $teamId);

        if (! $extracted instanceof ExtractedSignal) {
            // Still mark it read: the page was fetched and the money spent, and leaving the flag
            // off would have the next run fetch the same page again.
            $signal->forceFill(['article_fetched_at' => now()])->save();

            return true;
        }

        $this->apply($signal, $extracted);

        return true;
    }

    /**
     * Ask the extraction prompt again, with the article text where the snippet was.
     *
     * The article is wrapped in {@see UntrustedContent}, which is the whole reason this is safe to
     * do at all: a publisher's page is attacker-controlled text in exactly the way a search snippet
     * is, only longer. The fetcher has already stripped it to inert text and capped it at
     * `web-fetch.max_chars`, and the AI platform truncates and refuses beyond its own bound
     * (ADR 0017).
     */
    private function reextract(Signal $signal, string $text, int $teamId): ?ExtractedSignal
    {
        $definition = $signal->definition;

        try {
            $response = $this->ai->structured('signals.extract_event', [
                'business_profile' => '',
                'location' => (string) $signal->location,
                'event_types' => $definition === null
                    ? $signal->event_key
                    : $definition->key.': '.$definition->name.' — '.$definition->description,
                'event_type_keys' => $signal->event_key,
                'icp_geographies' => '',
                'icp_buying_signals' => '',
                'news_items' => UntrustedContent::from((string) json_encode([[
                    'item_index' => 0,
                    'title' => $signal->headline,
                    'snippet' => $text,
                    'source' => $signal->source_name,
                ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)),
            ]);
        } catch (AiCreditExceededException $e) {
            // Re-thrown rather than swallowed: it is the one AI failure that says *stop*, and the
            // caller is what owns the loop. Swallowing it here would have the enricher pay for a
            // page per remaining candidate to meet the same refusal.
            throw $e;
        } catch (AiPlatformException $e) {
            // **`AiPlatformException`, not the two leaf classes.** A transport failure or an
            // input-too-large refusal — a 12,000-character article is exactly the shape of the
            // second — would otherwise escape past the commit and fail an already-completed run,
            // which retries the job and re-pays for the Serper search (found by
            // `laravel-module-reviewer`).
            Log::channel('signals')->info('Could not re-extract an article; keeping the snippet’s answer.', [
                'team_id' => $teamId,
                'signal_id' => (int) $signal->getKey(),
                'error' => $e::class,
            ]);

            return null;
        }

        /** @var array<array-key, mixed> $events */
        $events = $response->data['events'] ?? [];
        $first = is_array($events[0] ?? null) ? $events[0] : null;

        if ($first === null) {
            return null;
        }

        $extracted = ExtractedSignal::fromAiItem($first);

        // **The type is not re-decided here.** A re-read that reclassified an event would move a
        // signal between the team's types behind their back, and the guard that makes an event key
        // trustworthy lives in `RunSignalWatchAction` (ADR 0018 §7) rather than here. If the full
        // text says this was never the event we thought, the honest answer is to leave the signal
        // as the team saw it and let them dismiss it.
        return $extracted->isEvent ? $extracted : null;
    }

    /**
     * Write back only the fields the full text can genuinely know better, and only where it found
     * something.
     *
     * **Filling gaps, never overwriting with nothing**, which is the same rule
     * `ConvertSignalToLeadAction` applies when it merges a lead: a re-extraction that returned no
     * amount must not erase the one the snippet found. The status, the type, the scores and the
     * cluster are all left alone — the full text is better evidence about *facts*, not about
     * whether the team should care.
     */
    private function apply(Signal $signal, ExtractedSignal $extracted): void
    {
        $fields = [
            'subject_person_name' => $extracted->subjectPersonName,
            'subject_person_role' => $extracted->subjectPersonRole,
            'subject_company_name' => $extracted->subjectCompanyName,
            'counterparty_name' => $extracted->counterpartyName,
        ];

        $updates = ['article_fetched_at' => now()];

        foreach ($fields as $column => $value) {
            if ($value !== null && $value !== '') {
                $updates[$column] = $value;
            }
        }

        // The amount and its currency move **together or not at all**: an amount without the
        // currency it was quoted in is ambiguous, which is the rule `ExtractedSignal` already
        // applies to the model's own output.
        if ($extracted->amountMinor !== null && $extracted->currency !== null) {
            $updates['amount_minor'] = $extracted->amountMinor;
            $updates['currency'] = $extracted->currency->value;
        }

        // The summary too, where the model wrote one: a summary from the whole article is simply
        // better than one from two sentences, and it is the field a member reads first.
        if (trim($extracted->summary) !== '') {
            $updates['summary'] = $extracted->summary;
        }

        $signal->forceFill($updates)->save();

        // **Re-scored, because the fields that just changed are inputs to the score.** The amount,
        // the role and the company name drive four of the eight components, and the candidate was
        // chosen on the *pre-enrichment* heat — so without this the card shows a $40m deal while
        // its score and its breakdown still describe the one with no amount in it, which is the
        // one inconsistency the explanation panel exists to prevent (found by
        // `laravel-module-reviewer`).
        //
        // The cluster is passed so breadth survives the re-score. `matched_lead_id` is
        // deliberately **not** re-run: a match is written as the row is built and carries a
        // timeline entry on the lead (S10), and silently re-pointing it later would leave that
        // entry describing a different company.
        $score = $this->scorer->score($signal, $signal->definition, $signal->cluster);

        $signal->forceFill([
            'heat' => $score->heat,
            'score_breakdown' => $score->toBreakdown(),
        ])->save();
    }

    /**
     * The source keys whose items point at an article worth reading — asked of the enum rather
     * than listed here, so a source added later is included or excluded by what it *is*.
     *
     * @return list<string>
     */
    private function readableSources(): array
    {
        $keys = [];

        foreach (SignalSourceKind::cases() as $kind) {
            if ($kind->carriesAFetchableArticle()) {
                $keys[] = $kind->value;
            }
        }

        return $keys;
    }

    /**
     * The signals in this run worth reading in full: hottest first, above the floor, not already
     * read, and only the cards the feed will actually show.
     *
     * `representatives()` matters here more than anywhere: paying to read all eight outlets'
     * versions of one acquisition would be the exact waste S11 was built to remove, reintroduced
     * at eight times the price.
     *
     * **A signal from a structured source is never a candidate** (S13b review, finding 12). A
     * Companies House filing arrives from the registry already parsed, with confidence and
     * relevance at 100 — so it sorts to the top of a heat-ordered list, and this would have paid
     * to fetch a registry company page and ask a language model to re-read the fact the registry
     * had already stated. `apply()` can then overwrite registry-asserted fields with that reading.
     * Spending money to make a certainty less certain, on the hottest cards first.
     *
     * The **webhook** is excluded too, and for the opposite reason: its `url` is a customer-supplied
     * address, and following one on the strength of the heat *our own* scorer gave the event is an
     * outbound request whose target the caller chose. The feed and the two search sources are what
     * this exists for — a snippet that is genuinely a truncated article.
     *
     * @return list<Signal>
     */
    private function candidates(SignalRun $run, int $teamId): array
    {
        $minHeat = max(0, min(100, (int) config('signals.article_fetch.min_heat', 70)));
        $perRun = max(1, (int) config('signals.article_fetch.max_per_run', 5));

        /** @var list<Signal> $candidates */
        $candidates = Signal::query()
            ->where('team_id', $teamId)
            ->where('signal_run_id', $run->getKey())
            ->representatives()
            ->whereNull('article_fetched_at')
            ->where('heat', '>=', $minHeat)
            ->whereIn('source_kind', $this->readableSources())
            // `cluster` as well as `definition`: the re-score after a successful read needs both,
            // and `preventLazyLoading` would otherwise turn a forgotten one into an exception on
            // the one path that is not allowed to throw.
            ->with(['definition', 'cluster'])
            ->orderByDesc('heat')
            ->limit($perRun)
            ->get()
            ->all();

        return $candidates;
    }

    /**
     * What is left of this team's monthly allowance, or null when there is no ceiling.
     *
     * **Asked of {@see SignalSpendGuard} since S16**, rather than computed from config here. The
     * arithmetic is unchanged and still lives in one place — articles read this calendar month ×
     * the per-fetch price, never `signal_runs.cost_micros`, which is the run's *whole* cost and
     * would make this ceiling shrink as the rest of the feature got busier — but the *ceiling* is
     * now the team's, which is their plan's once Billing is registered. A config cap of zero still
     * means "no ceiling configured" and arrives here as null.
     */
    private function remainingBudget(int $teamId, int $perFetch): ?int
    {
        if ($perFetch === 0) {
            return null;
        }

        $team = Team::query()->find($teamId);

        if (! $team instanceof Team) {
            // The team was deleted between the run's commit and this after-commit pass. Nothing to
            // bill and nobody to bill it to; the loop is about to find no candidates either.
            return 0;
        }

        return $this->guard->remainingArticleFetchMicros($team);
    }

    private function enabled(): bool
    {
        return (bool) config('signals.article_fetch.enabled', false);
    }
}
