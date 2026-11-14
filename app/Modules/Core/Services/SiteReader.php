<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\WebContentFetcher;
use App\Modules\Core\Support\RobotsPolicy;
use Illuminate\Support\Str;

/**
 * Reads the handful of pages on a business's own site that say what it does and who for: the
 * homepage, then the pages a visitor would open to find out — services, case studies, clients,
 * industries, about, pricing (docs/plans/ai-relevance-plan.md P3, ADR 0036).
 *
 * The background check used to read the homepage alone, and a homepage is a slogan. The customers
 * a business names, what it charges and who it will not work with are one click further in.
 *
 * It is a **reader, not a crawler**, and every bound is hard:
 *
 * - **One site.** Only links on the homepage, only the same host, never a link found on a second
 *   page. There is no frontier to grow.
 * - **`robots.txt` is asked before every request**, the homepage included — a site that says no is
 *   not read at all. {@see RobotsPolicy} fails closed on a file it cannot fetch.
 * - **Every request goes through {@see WebContentFetcher}**, so the SSRF guard, the redirect
 *   re-validation, the byte cap and the timeout are the one copy that already exists.
 * - **Pages, characters and seconds are each capped** (`config/web-fetch.php` → `site`). Whichever
 *   runs out first ends the read with what it has.
 *
 * It returns text for a prompt and stores nothing. Callers wrap every page as untrusted content.
 */
final class SiteReader
{
    /**
     * Path fragments that mark a page worth reading, best first. Proof of who buys (case studies,
     * clients) outranks a description of what is sold, which outranks the company talking about
     * itself.
     */
    private const array WANTED = [
        'case-stud', 'client', 'customer', 'our-work', 'work', 'portfolio', 'project',
        'service', 'what-we-do', 'solution', 'product', 'industr', 'sector',
        'who-we', 'about', 'pricing', 'price', 'plans',
    ];

    public function __construct(
        private readonly WebContentFetcher $fetcher,
        private readonly RobotsPolicy $robots,
    ) {}

    /**
     * @return list<array{url: string, text: string}> the homepage first; empty when it cannot be read
     */
    public function read(?string $url): array
    {
        if ($url === null || trim($url) === '' || ! $this->robots->allows($url)) {
            return [];
        }

        $deadline = microtime(true) + (int) config('web-fetch.site.max_seconds', 25);
        $budget = (int) config('web-fetch.site.max_total_chars', 40_000);
        $perPage = (int) config('web-fetch.site.page_chars', 8_000);

        $home = $this->fetcher->fetchPage($url);

        if ($home['text'] === '') {
            return [];
        }

        $pages = [['url' => $url, 'text' => Str::limit($home['text'], $perPage, '')]];
        $budget -= mb_strlen($pages[0]['text']);

        $wanted = array_slice($this->rank($home['links']), 0, max(0, (int) config('web-fetch.site.max_pages', 6) - 1));

        foreach ($wanted as $link) {
            if ($budget <= 0 || microtime(true) >= $deadline) {
                break;
            }

            if (! $this->robots->allows($link)) {
                continue;
            }

            $text = Str::limit($this->fetcher->fetchPage($link)['text'], min($perPage, $budget), '');

            if ($text !== '') {
                $pages[] = ['url' => $link, 'text' => $text];
                $budget -= mb_strlen($text);
            }
        }

        return $pages;
    }

    /**
     * The homepage's links worth following, best first: one per kind of page, shallow paths only.
     *
     * @param  list<string>  $links  same-host absolute URLs
     * @return list<string>
     */
    private function rank(array $links): array
    {
        $best = [];

        foreach ($links as $link) {
            $path = Str::lower((string) parse_url($link, PHP_URL_PATH));

            // A deep path is one case study, not the page that lists them; a file is not a page.
            if (substr_count(trim($path, '/'), '/') > 1 || preg_match('/\.(pdf|jpe?g|png|gif|svg|zip|docx?|xlsx?|mp4)$/', $path) === 1) {
                continue;
            }

            foreach (self::WANTED as $rank => $fragment) {
                if (str_contains($path, $fragment)) {
                    // The shortest URL of each kind: `/services` over `/services/web-design`.
                    if (! isset($best[$rank]) || strlen($link) < strlen($best[$rank])) {
                        $best[$rank] = $link;
                    }

                    break;
                }
            }
        }

        ksort($best);

        return array_values(array_unique($best));
    }
}
