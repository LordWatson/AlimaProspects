<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Fetches a public web page as plain, inert text — the single SSRF-safe fetch seam shared across
 * modules (Business Intelligence's background check, Enrichment's fact extraction). Implementations
 * MUST bound time and size, reject any host that resolves off the public internet, and return the
 * page stripped to text: the caller treats everything it returns as untrusted data, never
 * instructions. Config lives in `config/web-fetch.php`.
 */
interface WebContentFetcher
{
    /**
     * Fetch readable text from the given URL. Returns an empty string when the URL is missing,
     * unreachable, disallowed, or yields nothing usable — the caller decides how to handle an empty
     * result (it never throws for an ordinary fetch failure).
     */
    public function fetch(?string $url): string;

    /**
     * Fetch a **line-oriented** text file with its line breaks intact (Signals S12).
     *
     * {@see self::fetch()} is for prose headed to an AI prompt, so it strips markup and collapses
     * every run of whitespace to one space. That is exactly wrong for a file whose meaning is
     * carried by its lines: a `robots.txt` read through it arrives as a single line and parses as
     * no rules at all, so a control the product claims to honour silently honours nothing. (Found
     * by `laravel-module-reviewer`; the test that missed it used a double that preserved newlines.)
     *
     * The return distinguishes three outcomes where `fetch()` has two, because a caller enforcing a
     * rule has to tell "there is no such file" from "we could not ask":
     *
     * - **a string** — the file's contents, capped, line breaks preserved;
     * - **`''`** — the host answered and there is no such file (a 404). For `robots.txt` that means
     *   no restriction.
     * - **`null`** — it could not be read: a transport failure, a timeout, a 5xx. RFC 9309 says to
     *   treat that as disallow-all, which the caller can only do if it can see the difference.
     *
     * Implementations MUST apply the same host and size guards as {@see self::fetch()}.
     */
    public function fetchLines(?string $url): ?string;
}
