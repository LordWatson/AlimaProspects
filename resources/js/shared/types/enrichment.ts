// Enrichment & Data Quality (contacts, email verification, provenance) shared types (Phase 8).

/**
 * The free-tier email check's verdict. Nothing here asserts an address is deliverable — the MVP
 * does syntax plus MX/DNS and no SMTP probe (ADR 0008).
 */
export type EmailVerificationStatus =
    | 'unknown'
    | 'invalid_syntax'
    | 'disposable_domain'
    | 'no_mx_record'
    | 'mx_found';

/** A contact point derived for a lead during enrichment. */
export type LeadContact = {
    id: number;
    name: string | null;
    title: string | null;
    email: string | null;
    phone: string | null;
    email_verification_status: EmailVerificationStatus | null;
    /** Translation key, not a finished string. */
    email_verification_label: string | null;
    email_confidence: number | null;
    is_role_based: boolean;
    /** Flagged for compliance: a named person rather than a business role. */
    is_personal: boolean;
    source: string;
    source_url: string | null;
    collected_at: string | null;
};
