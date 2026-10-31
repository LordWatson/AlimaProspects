// Scoring & Matching (hybrid ICP-fit score) shared types (Phase 9).

export type ScoreGrade = 'A' | 'B' | 'C' | 'D';

/** Whether one ICP criterion matched the lead — `unknown` means the lead has no data for it. */
export type CriterionMatch = 'matched' | 'not_matched' | 'unknown';

export type FeedbackRating = 'good' | 'bad';

/** One row of a score's breakdown: how a criterion fared, and what in the lead settled it. */
export type ScoreBreakdownRow = {
    type: string;
    type_label: string;
    value: string;
    weight: number;
    match: CriterionMatch;
    match_label: string;
    /** Points of the deterministic score this row contributed (0 when it didn't match). */
    contribution: number;
    evidence: string | null;
};

/** The viewer's own verdict on a score. */
export type ScoreFeedback = {
    rating: FeedbackRating;
    comment: string | null;
    created_at: string | null;
};

/** A lead's hybrid ICP-fit score as the score screen and `/api/v1` receive it. */
export type LeadScore = {
    id: number;
    lead_id: number;
    score: number;
    grade: ScoreGrade;
    grade_label: string;
    grade_description: string;
    deterministic_score: number;
    /** Null when the AI half was unavailable — the rules carried the score alone. */
    ai_score: number | null;
    explanation: string | null;
    strengths: string[];
    concerns: string[];
    excluded: boolean;
    excluded_by: string | null;
    breakdown: ScoreBreakdownRow[];
    model: string | null;
    prompt_version: number | null;
    cost_micros: number;
    scored_at: string | null;
    icp?: { id: number; name: string; is_active: boolean } | null;
    my_feedback?: ScoreFeedback | null;
};

/** The grade summary carried on a lead row in a list (Phase 9). */
export type LeadScoreSummary = {
    score: number;
    grade: ScoreGrade;
    grade_label: string;
    excluded: boolean;
    scored_at: string | null;
};

/** The team's active ICP, as the score screen needs it. */
export type ScoringActiveIcp = {
    id: number;
    name: string;
    criteria_count: number;
};
