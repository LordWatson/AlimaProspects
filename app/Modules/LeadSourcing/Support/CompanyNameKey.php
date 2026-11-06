<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Support;

use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\LeadSource;
use Illuminate\Support\Str;

/**
 * One company name, as a comparable key (S10).
 *
 * **Extracted, not written.** `SourcedLead::dedupeKey()` has slugged a company name since Phase 7 to
 * make "ACME" and "Acme" the same lead, and S10 needs the same question answered about a signal's
 * subject: is this the company we already have? The plan's instruction was blunt and right —
 * *reuse it; do not write a third name-matching rule, because three normalisers is how two leads
 * become three.* So the rule moved here and {@see SourcedLead::dedupeKey()} calls it.
 *
 * Two keys, not one, and the difference matters because they are used for different things:
 *
 * - {@see self::for()} is the **strict** key: a slug, nothing removed. It is what a dedupe key is
 *   built from, and changing it would re-key every lead in every installation.
 * - {@see self::bare()} additionally drops the legal suffix — `ltd`, `limited`, `llc`, `plc`, `dmcc`
 *   and the rest — so "Acme Engineering Ltd" and "Acme Engineering" compare equal. That is a
 *   **weaker** claim and the matcher treats it as one: a news article writes a company's name the
 *   way a journalist does, and a registry writes it the way a filing does.
 */
final class CompanyNameKey
{
    /**
     * Legal forms, and **only** legal forms.
     *
     * Suffixes only, and only at the end: "Limited Brands" is a company whose name begins with a
     * word that is also a legal form, and stripping it anywhere would make it "brands".
     *
     * `holdings`, `group` and `the` were in this list for one draft and came back out, because they
     * are not legal forms — they are part of a company's name. Stripping them made **"Acme" match
     * "Acme Holdings Ltd"**, which is precisely the false positive S10 was warned about: those are
     * two names a press release distinguishes between, and a member who sees an event about one
     * pinned to the other stops trusting the feature. Setting aside "Ltd" is reading a filing;
     * setting aside "Group" is guessing.
     *
     * @var list<string>
     */
    private const array SUFFIXES = [
        'ltd', 'limited', 'ltd co', 'co ltd', 'company limited',
        'plc', 'llp', 'lp', 'llc', 'inc', 'incorporated', 'corp', 'corporation',
        'gmbh', 'ag', 'sa', 'sarl', 'bv', 'nv', 'pty', 'pty ltd', 'pte', 'pte ltd',
        // UAE forms, which the UAE pack's signals are full of.
        'llc fz', 'fzc', 'fze', 'fz llc', 'dmcc', 'jlt', 'difc', 'adgm', 'pjsc', 'psc',
    ];

    /** Longer than any column this is compared against, and longer than any real company name. */
    private const int MAX = 160;

    /**
     * The strict key: the slug `dedupeKey()` has always used.
     *
     * Returns an empty string for a name with nothing sluggable in it (punctuation, an emoji), and
     * the caller decides what that means — for the matcher it means "no match", which is the right
     * direction to fail in.
     */
    public static function for(string $name): string
    {
        return Str::limit(Str::slug(Str::squish($name)), self::MAX, '');
    }

    /**
     * The **name-only dedupe key**: the weakest of the four keys {@see SourcedLead::dedupeKey()}
     * can produce, and the only one derived from a value enrichment is allowed to correct.
     *
     * Lives here rather than in the DTO because Q1 gave it a second caller: when enrichment fixes a
     * web-sourced lead's company name, a key built from the **old** name would stay on the row and
     * that lead would quietly stop de-duplicating for ever. Recomputing it needs the same formula,
     * and the same reason `for()` moved here applies — two copies of a key formula is how one lead
     * becomes two.
     *
     * Provider-scoped on purpose: a bare name is not enough to claim two providers found the same
     * company (`docs/modules/lead-sourcing.md`).
     */
    public static function dedupeKeyFor(LeadSource $source, string $name): string
    {
        $slug = self::for($name);

        return 'name:'.$source->value.':'.Str::limit($slug, 120, '').':'.substr(sha1($slug), 0, 12);
    }

    /** Whether a stored dedupe key was derived from a company name (and so moves when it changes). */
    public static function isNameDerived(?string $dedupeKey): bool
    {
        return str_starts_with((string) $dedupeKey, 'name:');
    }

    /**
     * The looser key: the strict one with a trailing legal form removed.
     *
     * Applied repeatedly, because "Acme Trading Co Ltd" carries two and a single pass would leave
     * the first. It never returns an empty string from a non-empty input: a company actually called
     * "Limited" keeps its name rather than becoming nothing.
     */
    public static function bare(string $name): string
    {
        $key = self::for($name);

        if ($key === '') {
            return '';
        }

        $changed = true;

        while ($changed) {
            $changed = false;

            foreach (self::SUFFIXES as $suffix) {
                $slug = Str::slug($suffix);
                $tail = '-'.$slug;

                if (str_ends_with($key, $tail)) {
                    $shorter = Str::beforeLast($key, $tail);

                    if ($shorter !== '') {
                        $key = $shorter;
                        $changed = true;
                    }
                }
            }
        }

        return $key;
    }
}
