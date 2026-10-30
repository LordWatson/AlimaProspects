// Compliance & Privacy (Phase 15) shared types.

export type Market = 'uk' | 'uae';

export type LiaStatus = 'draft' | 'active' | 'withdrawn';

/**
 * A team's recorded legitimate-interest assessment for one market.
 *
 * `status_label` and `guidance` are **translation keys**, not sentences — the app is EN/AR and the
 * wording is chosen on the client. `in_force` and `has_expired` are computed on the server from the
 * same methods the discovery gate uses, so the screen and the gate cannot disagree about whether
 * sourcing is open.
 *
 * `id` is null when the team has never saved one for this market.
 */
export type LiaRecord = {
    id: number | null;
    market: Market;
    market_label: string;
    status: LiaStatus;
    status_label: string;
    guidance: string;
    purpose: string;
    necessity: string;
    balancing: string;
    safeguards: string | null;
    signals_scope: string | null;
    version: number;
    completed_at: string | null;
    review_due_at: string | null;
    withdrawn_at: string | null;
    in_force: boolean;
    has_expired: boolean;
    /** Negative once the review is overdue; null when there is nothing to count to. */
    review_due_in_days: number | null;
};

export type LiaForm = {
    market: Market;
    purpose: string;
    necessity: string;
    balancing: string;
    safeguards: string;
    signals_scope: string;
    sign: boolean;
};

/** A team's retention windows, and what the last nightly sweep did. */
export type RetentionPolicy = {
    id: number | null;
    is_enabled: boolean;
    uncontacted_lead_days: number;
    contacted_lead_days: number;
    last_swept_at: string | null;
    last_deleted_count: number;
    last_anonymised_count: number;
};

export type RetentionForm = {
    is_enabled: boolean;
    uncontacted_lead_days: number;
    contacted_lead_days: number;
};

/** What tonight's sweep would do under the windows currently saved. */
export type RetentionPreview = {
    deletable: number;
    anonymisable: number;
};

export type SubjectDataCategory =
    | 'contact'
    | 'lead'
    | 'message'
    | 'attempt'
    | 'signal'
    | 'suppression';

/**
 * One record a data-subject request turned up — a **candidate for a human to confirm**, never a
 * verdict. `matched_on` is what governs confidence: an address match is exact, a name match is a
 * guess.
 *
 * `detail` is the honest minimum for recognising the record (a masked address, a headline) and is
 * **data**, shown as it is. `labels` are translation keys for the rest of the line — a channel, an
 * outcome, a reason — because a server-composed English string would read as English in an Arabic
 * workspace. It deliberately does not repeat the subject's full contact details onto a screen the
 * whole team can open.
 */
export type SubjectRecord = {
    category: SubjectDataCategory;
    /** Translation key. */
    category_label: string;
    id: number;
    lead_id: number | null;
    company_name: string | null;
    detail: string;
    /** Translation keys, joined on the client. */
    labels: string[];
    matched_on: 'email' | 'phone' | 'name';
    recorded_at: string | null;
    erasable: boolean;
    /** Translation key, present only when `erasable` is false. */
    retained_reason: string | null;
};

export type SubjectDataReport = {
    query: string;
    matched_on: 'email' | 'phone' | 'name';
    records: SubjectRecord[];
    counts: Record<SubjectDataCategory, number>;
    total: number;
    erasable_count: number;
    /** The search hit its cap — narrow it rather than trusting the list is complete. */
    truncated: boolean;
    /** Whether this identity is already on the do-not-contact list, at either scope. */
    suppressed: boolean;
};

/** The particulars shown on the hosted privacy notice, from config so counsel can change them. */
export type PrivacyNotice = {
    version: string;
    updated_on: string;
    contact_email: string;
    hosting_location: string;
    uncontacted_days: number;
    contacted_days: number;
};
