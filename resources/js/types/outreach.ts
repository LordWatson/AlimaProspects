// Outreach (drafting, sending, logging, suppression) shared types (Phase 11).

import type { SupportedLocale } from './locale';

export type OutreachChannel = 'email' | 'linkedin' | 'call';

export type OutreachTone = 'direct' | 'warm' | 'formal' | 'consultative';

export type OutreachMessageStatus = 'draft' | 'sent' | 'logged' | 'failed';

export type ContactOutcome =
    | 'pending'
    | 'no_answer'
    | 'replied'
    | 'interested'
    | 'not_interested'
    | 'meeting_booked'
    | 'bounced'
    | 'opted_out';

export type SuppressionType = 'email' | 'domain' | 'phone';

export type SuppressionScope = 'global' | 'team';

/** Why the guard refused a channel. `label`/`guidance` are translation keys. */
export type OutreachBlockReason = {
    value:
        | 'restricted'
        | 'suppressed'
        | 'no_contact_point'
        | 'no_sender_identity'
        | 'sending_disabled';
    label: string;
    guidance: string;
    /**
     * A compliance refusal is final; a configuration one is something the team can go and fix —
     * which is a different sentence on screen.
     */
    is_compliance: boolean;
};

/** The guard's answer for one channel. `allowed` is the only thing to branch on. */
export type OutreachDecision = {
    channel: OutreachChannel;
    allowed: boolean;
    reasons: OutreachBlockReason[];
};

/** Keyed by channel, as the draft screen receives them. */
export type OutreachDecisions = Record<OutreachChannel, OutreachDecision>;

export type OutreachMessage = {
    id: number;
    lead_id: number;
    lead_contact_id: number | null;
    channel: OutreachChannel;
    /** Translation key, not a finished string. */
    channel_label: string;
    tone: OutreachTone;
    tone_label: string;
    locale: SupportedLocale;
    status: OutreachMessageStatus;
    status_label: string;
    subject: string | null;
    body: string;
    /** Every variant the model offered, so switching between them costs nothing. */
    variants: string[];
    template_id: number | null;
    error_message: string | null;
    sent_at: string | null;
    created_at: string | null;
    author?: { id: number; name: string } | null;
    /** Already used — not re-sendable. */
    is_spent: boolean;
    can_send: boolean;
    can_delete: boolean;
};

export type ContactAttempt = {
    id: number;
    lead_id: number;
    message_id: number | null;
    channel: OutreachChannel;
    channel_label: string;
    outcome: ContactOutcome;
    outcome_label: string;
    is_positive: boolean;
    occurred_at: string;
    notes: string | null;
    contact?: { id: number; name: string | null; title: string | null } | null;
    author?: { id: number; name: string } | null;
};

export type OutreachTemplate = {
    id: number;
    name: string;
    channel: OutreachChannel;
    channel_label: string;
    tone: OutreachTone;
    tone_label: string;
    locale: SupportedLocale;
    subject: string | null;
    body: string;
    is_default: boolean;
    created_at: string | null;
    can_update: boolean;
    can_delete: boolean;
};

/**
 * One do-not-contact entry. `value_preview` is the only identifying field there is — the table
 * holds a hash and a mask, never the address in the clear.
 */
export type OutreachSuppression = {
    id: number;
    scope: SuppressionScope;
    scope_label: string;
    type: SuppressionType;
    type_label: string;
    value_preview: string;
    reason: string;
    reason_label: string;
    note: string | null;
    suppressed_at: string;
    can_delete: boolean;
};

// --- Option lists sent by the server -------------------------------------------------------------

export type OutreachChannelOption = {
    value: OutreachChannel;
    label: string;
    description: string;
    is_sendable: boolean;
};

export type OutreachToneOption = {
    value: OutreachTone;
    label: string;
    description: string;
};

/** Each outcome carries whether picking it suppresses the contact, so the screen can warn first. */
export type ContactOutcomeOption = {
    value: ContactOutcome;
    label: string;
    suppresses: boolean;
    is_global: boolean;
};

export type OutreachLocaleOption = { value: SupportedLocale; label: string };

export type SuppressionTypeOption = { value: SuppressionType; label: string };

// --- Forms ---------------------------------------------------------------------------------------

export type DraftMessageForm = {
    channel: OutreachChannel;
    tone: OutreachTone;
    locale: SupportedLocale;
    lead_contact_id: number | null;
    template_id: number | null;
};

export type LogAttemptForm = {
    channel: OutreachChannel;
    outcome: ContactOutcome;
    occurred_at: string;
    lead_contact_id: number | null;
    message_id: number | null;
    notes: string;
};

export type OutreachTemplateForm = {
    name: string;
    channel: OutreachChannel;
    tone: OutreachTone;
    locale: SupportedLocale;
    subject: string;
    body: string;
    is_default: boolean;
};

export type SuppressIdentityForm = {
    type: SuppressionType;
    value: string;
    note: string;
};
