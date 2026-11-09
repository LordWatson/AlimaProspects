/*
 * Integrations (PL7) — outbound webhooks and the CRM framework.
 *
 * Mirrors the three API resources rather than the tables: `secret` is deliberately absent from
 * `WebhookEndpoint` and has no optional field it could arrive in, because it is returned once by
 * the call that mints it and never read back. The plaintext travels as its own prop (`newSecret`).
 */

/** One outbound endpoint, as `WebhookEndpointResource` sends it. */
export type WebhookEndpoint = {
    id: number;
    url: string;
    /** The last eight characters of the secret — enough to tell two endpoints apart. */
    hint: string;
    events: string[];
    is_active: boolean;
    failure_count: number;
    /** Set when Alima switched it off after repeated failures — not the member's own switch. */
    disabled_at: string | null;
    last_delivered_at: string | null;
    created_at: string | null;
};

/** One attempt, as `WebhookDeliveryResource` sends it. Never carries the payload. */
export type WebhookDelivery = {
    id: number;
    event: string;
    event_label: string;
    state: 'pending' | 'delivered' | 'failed';
    state_label: string;
    attempt: number;
    status_code: number | null;
    response_snippet: string | null;
    delivered_at: string | null;
    next_retry_at: string | null;
    created_at: string | null;
};

/** One subscribable event, from `WebhookEvent`. */
export type WebhookEventOption = {
    value: string;
    label: string;
    description: string;
    /** Fires once per record. Worth warning about before somebody ticks it. */
    high_volume: boolean;
};

/** The auto-sync rule, with null meaning "do not care about this threshold". */
export type CrmAutoSync = {
    enabled: boolean;
    min_confidence: number | null;
    min_fit: number | null;
};

/** One CRM connection, as `IntegrationResource` sends it. Never carries a token. */
export type CrmIntegration = {
    id: number;
    provider: string;
    provider_label: string;
    status: 'connected' | 'needs_reauth' | 'error' | 'disconnected';
    status_label: string;
    account_name: string | null;
    auto_sync: CrmAutoSync;
    create_deals: boolean;
    last_synced_at: string | null;
    last_error: string | null;
};

/** A provider Alima knows about, and whether this build can talk to it. */
export type CrmProviderOption = {
    value: string;
    label: string;
    available: boolean;
};
