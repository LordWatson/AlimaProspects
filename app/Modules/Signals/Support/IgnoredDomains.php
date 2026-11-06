<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support;

use App\Modules\Signals\Services\SignalItemNormaliser;
use Illuminate\Support\Str;

/**
 * Whether a result's source is on the skip list: the product's own, plus the team's (S9).
 *
 * **Extracted because a run and a preview need opposite behaviour from the same rule.** A run drops
 * an ignored source silently, which is right — nobody wants a property portal in their feed. A
 * preview has to *show* it, labelled `ignored_domain`, because "your templates work and every result
 * is on your skip list" is a diagnosis a member could never reach from an empty list. Both have to
 * agree about what "ignored" means, and two copies of a host-matching rule would not: the subtle
 * half is the normalisation, and a preview that was 1% more lenient than the run would tell a member
 * something true about a search nobody will ever perform.
 *
 * The matching is **host-or-parent**: `bayut.com` on the list blocks `www.bayut.com` and
 * `news.bayut.com`, because a team listing a competitor means the competitor. Both sides are
 * normalised — the list because it is typed by a person, the host because it is returned by a
 * provider — so a trailing-dot FQDN (`bayut.com.`) or a tab in the authority (`bayut\t.com`) cannot
 * walk past a list the team believed excluded it.
 *
 * **Since S11 an entry also matches the outlet's name** ({@see self::blocksItem()}), and that is a
 * defect fix rather than a convenience. Serper sometimes returns a Google redirect in place of the
 * publisher's link, so every redirected item's host is `google.com` — a host-only list cannot
 * express "not Khaleej Times" about those at all, and adding `google.com` to the list would
 * silently drop a large share of every result (`docs/modules/signals.md`, "Known limitations",
 * consequence 2). Matching the `source_name` as well closes it, and it also makes the list behave
 * the way a team expects when they type "Khaleej Times" instead of a domain.
 *
 * The two comparisons cannot be confused with one another: a host is matched as a host and a name
 * as a slug, so an entry of `bayut.com` blocks the host `news.bayut.com` and an outlet literally
 * calling itself "Bayut.com", and nothing else.
 */
final class IgnoredDomains
{
    public function __construct(private readonly SignalItemNormaliser $normaliser) {}

    /**
     * The host of a URL, normalised for comparison, or null when it has none we can read.
     *
     * Null is the caller's cue to drop the item rather than let it through unchecked: a link whose
     * authority cannot be parsed is not a link we can say anything about.
     */
    public function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $this->normaliser->host(is_string($host) ? $host : '');
    }

    /**
     * Whether an already-normalised host is blocked, by the global list or the caller's own.
     *
     * @param  list<string>  $extra  The team's own list, which only they can know (S6).
     */
    public function blocks(string $host, array $extra = []): bool
    {
        foreach ($this->list($extra) as $blocked) {
            if ($host === $blocked || Str::endsWith($host, '.'.$blocked)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a whole URL is blocked. Also true when its host cannot be read at all.
     *
     * @param  list<string>  $extra
     */
    public function blocksUrl(string $url, array $extra = []): bool
    {
        $host = $this->hostOf($url);

        return $host === null || $this->blocks($host, $extra);
    }

    /**
     * Whether an outlet's name is on the list (S11).
     *
     * Compared as a slug on both sides, so "Khaleej Times", "khaleej times" and "Khaleej  Times"
     * are the same outlet. A blank name is never blocked: "we were not told who published this" is
     * not the same statement as "this publisher is excluded", and treating it as one would drop
     * every item whose provider omitted the field.
     *
     * @param  list<string>  $extra
     */
    public function blocksSource(?string $source, array $extra = []): bool
    {
        $key = $this->normaliser->sourceKey($source);

        if ($key === '') {
            return false;
        }

        foreach ($this->list($extra) as $blocked) {
            if (Str::slug($blocked) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a result is blocked, by its link **or** by the outlet that published it.
     *
     * The one question a run and a preview both ask, and the reason this class exists: the two must
     * not disagree by a hair of normalisation (see the class docblock).
     *
     * @param  list<string>  $extra
     */
    public function blocksItem(string $url, ?string $source, array $extra = []): bool
    {
        return $this->blocksUrl($url, $extra) || $this->blocksSource($source, $extra);
    }

    /**
     * One entry a person typed, as a host the provider's results can be compared against.
     *
     * **The only copy of this rule**, which is the correction the S9 review forced. A member types
     * what they see in a browser — a whole URL out of the address bar, a `www.`, occasionally a
     * Unicode domain — and the provider returns a bare punycode host. The normalisation lived in
     * two places for one phase: `SignalQuerySpec` did the full job for a run, and this class did a
     * shorter version for the preview, so a watch holding `https://rival.ae/news` had its results
     * dropped by a run and **kept by the preview**. A preview that is more lenient than the run is
     * worse than no preview: it shows somebody a result they will never receive.
     */
    public static function normalise(string $entry): string
    {
        $value = Str::lower(trim($entry));

        if ($value === '') {
            return '';
        }

        if (Str::contains($value, '/')) {
            $host = parse_url(Str::startsWith($value, ['http://', 'https://']) ? $value : 'https://'.$value, PHP_URL_HOST);
            $value = is_string($host) ? $host : $value;
        }

        // A team that types a Unicode domain would otherwise never match the punycode host the
        // provider returns.
        if (! mb_check_encoding($value, 'ASCII') && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($value);
            $value = is_string($ascii) && $ascii !== '' ? $ascii : $value;
        }

        // The trailing dot matters: `rival.ae.` is a valid absolute FQDN resolving to the same
        // place, but a different string — and the comparison downstream is a string one.
        return Str::replaceStart('www.', '', rtrim($value, '.'));
    }

    /**
     * A caller's list, normalised and de-duplicated.
     *
     * @param  list<string>  $entries
     * @return list<string>
     */
    public static function normaliseAll(array $entries): array
    {
        $out = [];

        foreach ($entries as $entry) {
            $value = self::normalise($entry);

            if ($value !== '' && ! in_array($value, $out, strict: true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * The effective list: the configured sources plus the caller's, normalised and de-duplicated.
     *
     * @param  list<string>  $extra
     * @return list<string>
     */
    private function list(array $extra): array
    {
        /** @var list<string> $configured */
        $configured = (array) config('signals.ignore_domains', []);

        return self::normaliseAll([...$configured, ...$extra]);
    }
}
