// Enrichment & Data Quality (contacts, email verification, provenance) shared types (Phase 8).

/**
 * What is known about an email address, however it was learned. Ordered weakest → strongest.
 *
 * The first five are the free-tier check: syntax plus MX/DNS and no SMTP probe (ADR 0008), so the
 * strongest it can say is that the domain is *routable*. The last three arrive with PL2, when a
 * paid verifier can answer the question the free check cannot — cases on the same union rather
 * than a second one, because a column with two vocabularies is a column every reader has to ask
 * which one it is speaking.
 *
 * `accept_all` is the one worth reading twice: a catch-all domain cannot **disprove** an address,
 * which looks like a pass and is not one.
 */
export type EmailVerificationStatus =
    | 'unknown'
    | 'invalid_syntax'
    | 'disposable_domain'
    | 'no_mx_record'
    | 'unverifiable'
    | 'risky'
    | 'undeliverable'
    | 'mx_found'
    | 'accept_all'
    | 'verified';

/**
 * What is known about a number's UK do-not-call registration (PL5).
 *
 * `unchecked` means nobody asked a register, which is neither a pass nor a block;
 * `not_applicable` means the number is outside TPS/CTPS altogether — a UAE number is not clear of
 * a UK register, it is simply not covered by one.
 */
export type TpsStatus = 'unchecked' | 'clear' | 'registered' | 'not_applicable';

/** What sort of number a phone contact is (PL2). A switchboard is a company's, a mobile a person's. */
export type PhoneType = 'mobile' | 'direct' | 'switchboard' | 'unknown';

/** Where one contact is in the paid-enrichment pipeline (PL2) — per contact, not per lead. */
export type ContactEnrichmentState =
    | 'idle'
    | 'queued'
    | 'running'
    | 'done'
    | 'failed';

/**
 * A contact point for a lead.
 *
 * **`email` and `phone` are what may be shown**, already masked by the server where a reveal is
 * owed (PL2). There is deliberately no raw value beside them under another name: a `masked_email`
 * next to a plain `email` is a mask any client can step over.
 *
 * `requires_email_reveal` is **not** "do we hold an address". An address read off the company's
 * own contact page needs no reveal — only something a paid vendor produced does (ADR 0029) — so a
 * client asking whether a contact is reachable at all reads `email_verification_status` and
 * `has_phone`.
 */
export type LeadContact = {
    id: number;
    name: string | null;
    first_name: string | null;
    last_name: string | null;
    title: string | null;
    department: string | null;
    /** The vendor's own word for the level, free text — ranked server-side, never an enum. */
    seniority: string | null;
    linkedin_url: string | null;

    /** Whole, or `j***@acme.com`. Already masked where a reveal is owed. */
    email: string | null;
    /** Whole, or `***4821`. */
    phone: string | null;
    requires_email_reveal: boolean;
    requires_phone_reveal: boolean;
    revealed_email_at: string | null;
    revealed_phone_at: string | null;

    email_verification_status: EmailVerificationStatus | null;
    /** Translation key, not a finished string. */
    email_verification_label: string | null;
    email_confidence: number | null;
    email_verified_at: string | null;
    /** The provider key behind the verdict. Null means Alima's own free-tier check. */
    email_source: string | null;

    /** Whether a number exists, for a client choosing between "Reveal" and "Find a number". */
    has_phone: boolean;
    phone_type: PhoneType | null;
    /** Translation key, not a finished string. */
    phone_type_label: string | null;
    /** Only a mobile can take a WhatsApp link; a landline would open an undeliverable chat. */
    phone_accepts_messaging: boolean;
    phone_source: string | null;

    /**
     * The do-not-call verdict (PL5). **`unchecked` is not `clear`** — three of the four values
     * permit a call and only two of them mean the number was ever screened, so read
     * `allows_dial_link` to decide and show `tps_status_label` to explain. Comparing the status to
     * one case is the bug the enum is shaped to prevent.
     */
    tps_status: TpsStatus;
    /** Translation key, not a finished string. */
    tps_status_label: string;
    tps_checked_at: string | null;
    /** False only for a registered number: show the value, offer no one-tap call. */
    allows_dial_link: boolean;

    is_role_based: boolean;
    /** Flagged for compliance: a named person rather than a business role. */
    is_personal: boolean;
    /** The address was guessed from the domain and a name, not read off a page. */
    is_inferred: boolean;

    enrichment_state: ContactEnrichmentState;
    /** Translation key, not a finished string. */
    enrichment_state_label: string;
    enrichment_in_flight: boolean;
    enriched_at: string | null;

    source: string;
    source_url: string | null;
    collected_at: string | null;
};
