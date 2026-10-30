// Notifications (bell, history, preferences, push) shared types (Phase 12).

export type NotificationType =
    | 'discovery_complete'
    | 'top_leads'
    | 'task_due'
    | 'lead_assigned'
    | 'signals_detected'
    | 'credit_low'
    | 'digest';

export type NotificationChannel = 'in_app' | 'mail' | 'push';

export type DigestFrequency = 'off' | 'daily' | 'weekly';

export type NotificationGroup = 'finding' | 'my_work' | 'account' | 'summary';

/**
 * One notification as the bell reads it.
 *
 * `title` and `body` are **translation keys** and `params` the values to interpolate — never
 * finished prose. The row may have been written days ago and the member may since have switched the
 * app to Arabic (ADR 0007), so the sentence is composed here with `t()`.
 */
export type AppNotification = {
    id: string;
    type: NotificationType | null;
    title: string;
    body: string;
    params: Record<string, string | number>;
    url: string | null;
    read_at: string | null;
    created_at: string | null;
};

/** The shared prop the bell renders from, present on every page. */
export type NotificationBell = {
    unread: number;
    items: AppNotification[];
};

/** The payload broadcast on the member's own private channel as `notification.created`. */
export type NotificationBroadcast = {
    id: string;
    type: NotificationType;
    title: string;
    body: string;
    params: Record<string, string | number>;
    url: string | null;
    created_at: string;
};

// --- The settings screen -------------------------------------------------------------------------

export type NotificationTypeOption = {
    value: NotificationType;
    label: string;
    description: string;
    group: NotificationGroup;
    /** False when the type is not this member's to receive (owners and admins only). */
    available: boolean;
};

export type NotificationChannelOption = {
    value: NotificationChannel;
    label: string;
    description: string;
};

export type DigestFrequencyOption = { value: DigestFrequency; label: string };

export type NotificationGroupOption = {
    value: NotificationGroup;
    label: string;
};

/** Effective channels per type — what *will* happen, defaults included. */
export type NotificationPreferenceMap = Record<string, NotificationChannel[]>;

export type NotificationPreferencesForm = {
    channels: NotificationPreferenceMap;
    digest: DigestFrequency;
    digest_hour: number;
    muted_until: string | null;
};
