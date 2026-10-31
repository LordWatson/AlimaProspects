// AI Insights (the feed, Phase 14) shared types.

import type { OutreachChannel } from './outreach';
import type { ScoreGrade } from './scoring';

export type InsightType = 'stale_leads' | 'contact_today' | 'periodic_digest';

export type InsightStatus = 'new' | 'dismissed';

export type InsightSeverity = 'info' | 'warning' | 'action';

/**
 * One company named by an insight. Never a person and never a contact detail — an insight points
 * at a lead, and the lead owns that data.
 */
export type InsightLead = {
    id: number;
    company_name: string;
    grade: ScoreGrade | null;
    score: number | null;
    /** `stale_leads` only: how long this one has been quiet. */
    days_quiet?: number | null;
    /** `contact_today` only: the channel the Outreach guard left open. */
    channel?: OutreachChannel;
    /**
     * `contact_today` only. True when direct marketing is closed for this lead and only an
     * introduction is open (the UAE prior-consent case) — the card says so rather than suggesting
     * an email that would be blocked.
     */
    restricted?: boolean;
};

/**
 * The structured half of an insight. Which keys are present depends on the type, which is why
 * every one of them is optional: `stale_leads` and `contact_today` carry `leads`, the AI digest
 * carries the figures it was reasoning about.
 */
export type InsightPayload = {
    count?: number;
    days?: number;
    leads?: InsightLead[];
    topic?: string | null;
    metrics?: Record<string, number>;
};

/**
 * One insight as the feed reads it.
 *
 * **`headline` and `body` are null unless `is_written_by_ai`.** The two deterministic types store
 * no prose and are worded on the client from `type` + `payload`, so they read in whichever
 * language the member has the app set to (ADR 0007); only the AI digest carries stored prose, and
 * that is written once in the team's locale.
 */
export type Insight = {
    id: number;
    type: InsightType;
    /** Translation key. */
    type_label: string;
    /** Translation key. */
    type_description: string;
    is_written_by_ai: boolean;
    status: InsightStatus;
    /** Translation key. */
    status_label: string;
    severity: InsightSeverity;
    /** Translation key. */
    severity_label: string;
    headline: string | null;
    body: string | null;
    payload: InsightPayload;
    period_start: string | null;
    period_end: string | null;
    generated_at: string;
    dismissed_at: string | null;
    deletes_in_days: number;
};

export type InsightStatusCounts = Record<InsightStatus, number>;

/** When the next run is due, in the team's own clock — so an empty feed can say so. */
export type InsightSchedule = {
    /** 0–23, in `timezone`. */
    hour: number;
    timezone: string;
};
