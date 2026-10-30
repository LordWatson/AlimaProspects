// Leads Workspace (list, detail, timeline, saved views) shared types (Phase 10).

import type { EnrichmentStatus, LeadSource } from './lead-sourcing';
import type { ScoreGrade } from './scoring';

export type LeadActivityType =
    // What the platform did (Phase 10).
    | 'discovered'
    | 'enriched'
    | 'scored'
    // What the team did (Phase 10b).
    | 'stage_changed'
    | 'note_added'
    | 'task_created'
    | 'task_completed'
    | 'assigned'
    | 'unassigned'
    | 'tags_changed'
    | 'imported'
    // What the team did with a signal (Signals, S4).
    | 'signal_converted';

/**
 * One timeline entry. The server sends `type` + `properties`, never a formed sentence — the
 * wording is composed on the client so it can be EN or AR (ADR 0007).
 */
export type LeadActivity = {
    id: number;
    type: LeadActivityType;
    /** Translation key, not a finished string. */
    type_label: string;
    /** True when Alima did it rather than a member. */
    is_automatic: boolean;
    properties: Record<string, string | number | boolean | null>;
    user?: { id: number; name: string } | null;
    occurred_at: string;
};

export type LeadSort = 'newest' | 'company' | 'fit' | 'freshness';
export type LeadSortDirection = 'asc' | 'desc';

/** The filter bar's bound state — every key always present, mirroring `LeadFilters::toFormState()`. */
export type LeadFilterState = {
    search: string;
    grades: ScoreGrade[];
    sources: LeadSource[];
    enrichment_statuses: EnrichmentStatus[];
    country: string;
    stages: number[];
    tags: number[];
    assignees: number[];
    unassigned: boolean;
    sort: LeadSort;
    direction: LeadSortDirection;
};

/** Whether reaching a stage ends the lead's journey, and how. */
export type StageOutcome = 'won' | 'lost';

/** One column of the team's board. `name` is the team's own word, never translated. */
export type PipelineStage = {
    id: number;
    name: string;
    slug: string;
    position: number;
    is_default: boolean;
    outcome: StageOutcome | null;
    /** Translation key, not a finished string. */
    outcome_label: string | null;
    leads_count?: number;
};

/** A team's label for leads. */
export type LeadTag = {
    id: number;
    name: string;
    leads_count?: number;
};

export type LeadTaskStatus = 'open' | 'done';

export type LeadTask = {
    id: number;
    title: string;
    status: LeadTaskStatus;
    /** Translation key, not a finished string. */
    status_label: string;
    due_at: string | null;
    remind_at: string | null;
    /** Computed server-side against the stored UTC column, so a wrong client clock can't decide. */
    is_overdue: boolean;
    assignee?: { id: number; name: string } | null;
    completed_at: string | null;
    can_complete: boolean;
    can_delete: boolean;
};

export type LeadNote = {
    id: number;
    body: string;
    author?: string | null;
    created_at: string | null;
    can_delete: boolean;
};

/** A member of the team, for the assignee filter and the assign picker. */
export type TeamMemberOption = {
    id: number;
    name: string;
};

/** What a bulk action does to the selected leads. */
export type BulkLeadAction =
    | 'add_tags'
    | 'remove_tags'
    | 'assign'
    | 'unassign'
    | 'move_stage';

/** One option in the filter bar; `label` is a translation key. */
export type LeadFilterOption = {
    value: string;
    label: string;
};

export type LeadFilterOptions = {
    grades: LeadFilterOption[];
    sources: LeadFilterOption[];
    enrichment_statuses: LeadFilterOption[];
    sorts: LeadFilterOption[];
};

/** A member's named set of leads-list filters. */
export type SavedView = {
    id: number;
    name: string;
    filters: Partial<Record<keyof LeadFilterState, unknown>>;
    is_shared: boolean;
    is_mine: boolean;
    owner?: string | null;
};

/** One of Laravel's paginator links. */
export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

/**
 * A Laravel API-resource paginator, as Inertia receives it. Note the two different `links`: the
 * top-level one is the first/last/prev/next set, while the per-page links live under `meta`.
 */
export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        links: PaginationLink[];
        path: string;
        per_page: number;
        to: number | null;
        total: number;
    };
};

/** The run that found a lead, as the detail screen names it. */
export type LeadDiscoveryRun = {
    id: number;
    source_label: string;
    created_at: string | null;
};

/**
 * Where a lead came from when "a provider found it" isn't the whole story — a converted signal,
 * say. Deliberately generic: the panel shows a headline, a detail line and the two links, and
 * knows nothing about which module filled it in (mirrors `LeadsWorkspace\Data\LeadOrigin`).
 */
export type LeadOrigin = {
    /** Machine key for the origin type — the panel keys its icon and heading off this. */
    kind: string;
    /** Translation key, not a finished string. */
    label: string;
    headline: string | null;
    detail: string | null;
    url: string | null;
    source_name: string | null;
    occurred_at: string | null;
    href: string | null;
};
