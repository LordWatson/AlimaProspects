<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Enums;

use App\Modules\Enrichment\Services\LeadReadiness;

/**
 * What we know about an email address, **however we came to know it**. Ordered weakest → strongest.
 *
 * The first five cases are the free-tier check (Phase 8): syntax → disposable-domain → MX/DNS, no
 * SMTP probe, so the strongest thing it can say is that the domain is *routable*
 * ({@see self::MxFound}) rather than that the mailbox exists.
 *
 * The last three arrive with PL2, when a paid verifier can answer the question the free check
 * cannot. They are cases on **this** enum rather than a second `EmailStatus` beside it, which is
 * the decision worth stating: a column with two vocabularies is a column every reader has to ask
 * which one it is speaking, and the one that forgets is the one that shows a verified address as
 * unverified. A vendor's own statuses are **mapped onto these** by its adapter.
 *
 * {@see self::confidence()} stays a soft 0–100 signal for Scoring and never a hard claim (ADR
 * 0008): even {@see self::Verified} is a vendor's word about a moment in time, which is why it
 * stops short of 100.
 *
 * Values are stable storage keys; {@see self::label()} is the display name. No magic strings.
 */
enum EmailVerificationStatus: string
{
    /** Not checked, or the address was absent. */
    case Unknown = 'unknown';

    /** Failed a basic RFC syntax check — almost certainly unusable. */
    case InvalidSyntax = 'invalid_syntax';

    /** The domain is a known throwaway / disposable-mail provider. */
    case DisposableDomain = 'disposable_domain';

    /** Valid syntax but the domain publishes no MX (and no A) record — mail can't be routed. */
    case NoMxRecord = 'no_mx_record';

    /** A verifier could not reach a verdict — a cautious server, a timeout, a grey area. */
    case Unverifiable = 'unverifiable';

    /** A verifier says the mailbox is a spam trap, an abuse address, or otherwise not to be mailed. */
    case Risky = 'risky';

    /** A verifier says there is no such mailbox. Stronger than our own syntax check, and final. */
    case Undeliverable = 'undeliverable';

    /** Valid syntax and the domain accepts mail (MX present) — routable, not proven deliverable. */
    case MxFound = 'mx_found';

    /** The domain accepts everything, so the address cannot be disproved — or confirmed. */
    case AcceptAll = 'accept_all';

    /** A verifier confirmed the mailbox exists. The strongest thing anybody will tell us. */
    case Verified = 'verified';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Unknown',
            self::InvalidSyntax => 'Invalid syntax',
            self::DisposableDomain => 'Disposable domain',
            self::NoMxRecord => 'No mail server',
            self::MxFound => 'Mail server found',
            self::Unverifiable => 'Could not be checked',
            self::Risky => 'Risky — do not mail',
            self::Undeliverable => 'No such mailbox',
            self::AcceptAll => 'Accepts all mail',
            self::Verified => 'Verified',
        };
    }

    /**
     * A soft 0–100 confidence that the address is usable. Deliberately capped below certainty:
     * MX presence proves routability, not that the mailbox exists.
     */
    public function confidence(): int
    {
        return match ($this) {
            self::Unknown => 0,
            self::InvalidSyntax => 0,
            self::DisposableDomain => 10,
            self::NoMxRecord => 20,
            self::MxFound => 60,
            self::Unverifiable => 30,
            // Lower than "we have not checked": a verifier has actively told us not to write here.
            self::Risky => 5,
            self::Undeliverable => 0,
            // The domain cannot disprove it either, so it is better than routable and short of
            // confirmed — and the thing a team most often mistakes for confirmed.
            self::AcceptAll => 70,
            // Not 100. A mailbox that existed this morning is a vendor's word about this morning.
            self::Verified => 95,
        };
    }

    /**
     * Whether mail sent to this address has somewhere to arrive — the question
     * {@see LeadReadiness} and the de-duplicator are actually
     * asking when they look at a contact.
     *
     * A method rather than `=== MxFound` at four call sites, which is what they all were before
     * PL2 widened this enum. The literal comparison was correct while `MxFound` was the best
     * outcome that existed; the moment a vendor could return something **better**, every one of
     * those sites would have quietly scored a verified address lower than a merely routable one.
     */
    public function isRoutable(): bool
    {
        return match ($this) {
            self::MxFound, self::AcceptAll, self::Verified => true,
            self::Unknown, self::InvalidSyntax, self::DisposableDomain,
            self::NoMxRecord, self::Unverifiable, self::Risky, self::Undeliverable => false,
        };
    }

    /**
     * Whether a **paid** verifier produced this outcome — the three cases the free check cannot
     * reach. Read by the reveal rule: what a vendor found is masked until a member spends on it,
     * and what we read off a public page is not (ADR 0029).
     */
    public function isVendorVerdict(): bool
    {
        return match ($this) {
            self::Verified, self::AcceptAll, self::Risky, self::Undeliverable, self::Unverifiable => true,
            self::Unknown, self::InvalidSyntax, self::DisposableDomain, self::NoMxRecord, self::MxFound => false,
        };
    }

    /**
     * The storage values a query can use to mean "routable", for the places that have to ask this
     * in SQL rather than in PHP.
     *
     * @return list<string>
     */
    public static function routableValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isRoutable()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
