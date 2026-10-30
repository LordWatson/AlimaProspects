<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    ClipboardCopy,
    Send,
    ShieldAlert,
    Sparkles,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import ChannelPicker from '@/components/outreach/ChannelPicker.vue';
import LogAttemptDialog from '@/components/outreach/LogAttemptDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type {
    ContactAttempt,
    ContactOutcomeOption,
    DraftMessageForm,
    Lead,
    LeadContact,
    OutreachChannel,
    OutreachChannelOption,
    OutreachDecisions,
    OutreachLocaleOption,
    OutreachMessage,
    OutreachRestriction,
    OutreachTemplate,
    OutreachToneOption,
} from '@/types';

/*
 * Reach one company — draft, edit, copy, send, log.
 *
 * Two columns, and which side things are on is the argument the screen makes. On the left is the
 * **message**: the channel, the brief, the draft and the three things a member can do with it.
 * On the right is the **evidence** — who they are writing to, what the lead carries, and every
 * approach the team has already made. A member about to write a cold email should be able to see,
 * without scrolling, that a colleague called them on Tuesday.
 *
 * The compliance rules are built into the shape rather than bolted on:
 *
 *  - A channel the lead may not be approached on is **disabled with its reason beside it**, not
 *    hidden (see `ChannelPicker`).
 *  - A lead carrying a restriction says so at the top of the column, as a standing note — before
 *    the member tries, not as a refusal afterwards.
 *  - **Copy** is a first-class action, equal to Send. For LinkedIn and call scripts it is the only
 *    one, and for a team with no verified sender it is how the work still gets done.
 *
 * Brand: cream page, one Ink hero, Acid Lime as the single accent on the chosen channel and the
 * draft's own card. Signal Orange appears nowhere — every state here is a status, and Orange is
 * decorative only (brand-tokens.md §2.1).
 */
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Leads', href: '/leads' }],
    },
});

const props = defineProps<{
    lead: Lead;
    contacts: LeadContact[];
    selectedContactId: number | null;
    messages: OutreachMessage[];
    attempts: ContactAttempt[];
    templates: OutreachTemplate[];
    options: {
        channels: OutreachChannelOption[];
        tones: OutreachToneOption[];
        outcomes: ContactOutcomeOption[];
        locales: OutreachLocaleOption[];
    };
    decisions: OutreachDecisions;
    restriction: OutreachRestriction | null;
    can: { work: boolean };
}>();

const { t } = useI18n();
const { dateTime } = useFormat();

/** A nullable timestamp, formatted in the team's timezone, or an em dash. */
function when(value: string | null): string {
    return value === null ? '—' : dateTime(value);
}

// --- Drafting ------------------------------------------------------------------------------------
/** Open on a channel the lead may actually be approached on, so the screen opens usable. */
function firstOpenChannel(): OutreachChannel {
    const open = props.options.channels.find(
        (channel) => props.decisions[channel.value]?.allowed,
    );

    return open?.value ?? 'linkedin';
}

const draft = useForm<DraftMessageForm>({
    channel: firstOpenChannel(),
    tone: 'direct',
    locale: props.lead.country === 'AE' ? 'ar' : 'en',
    lead_contact_id: props.selectedContactId,
    template_id: null,
});

/** The team's briefs for the channel being drafted — a brief is written for one channel. */
const templatesForChannel = computed(() =>
    props.templates.filter((template) => template.channel === draft.channel),
);

// Switching channel invalidates the chosen brief, and silently sending the wrong channel's brief
// to the model would produce a draft shaped for neither.
watch(
    () => draft.channel,
    () => {
        if (
            draft.template_id !== null &&
            !templatesForChannel.value.some((t) => t.id === draft.template_id)
        ) {
            draft.template_id = null;
        }
    },
);

function generate(): void {
    draft.post(`/leads/${props.lead.id}/outreach/draft`, {
        preserveScroll: true,
        onSuccess: () => {
            // The newest draft is the one the member just asked for.
            selectedMessageId.value = null;
            announce(t('Draft ready.'));
        },
    });
}

// --- The draft on screen -------------------------------------------------------------------------
const selectedMessageId = ref<number | null>(null);

/** The draft being worked on: the one picked, or the newest. */
const current = computed<OutreachMessage | null>(() => {
    if (selectedMessageId.value !== null) {
        return (
            props.messages.find((m) => m.id === selectedMessageId.value) ?? null
        );
    }

    return props.messages[0] ?? null;
});

const edit = useForm<{ subject: string; body: string }>({
    subject: '',
    body: '',
});

// Reseeded whenever the draft on screen changes — including after a save, so the fields show what
// the server stored rather than what was typed.
watch(
    current,
    (message) => {
        edit.defaults({
            subject: message?.subject ?? '',
            body: message?.body ?? '',
        });
        edit.reset();
        edit.clearErrors();
    },
    { immediate: true },
);

const isDirty = computed(
    () =>
        current.value !== null &&
        (edit.body !== (current.value.body ?? '') ||
            edit.subject !== (current.value.subject ?? '')),
);

function save(): void {
    if (current.value === null) return;

    edit.patch(`/outreach/messages/${current.value.id}`, {
        preserveScroll: true,
        onSuccess: () => announce(t('Changes saved.')),
    });
}

function send(): void {
    if (current.value === null) return;

    router.post(
        `/outreach/messages/${current.value.id}/send`,
        {},
        { preserveScroll: true },
    );
}

function remove(message: OutreachMessage): void {
    router.delete(`/outreach/messages/${message.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            selectedMessageId.value = null;
            announce(t('Draft deleted.'));
        },
    });
}

/**
 * Copy the message as the member would paste it — subject and body for an email, the body alone
 * for a LinkedIn message or a call script.
 */
const copied = ref(false);

async function copy(): Promise<void> {
    if (current.value === null) return;

    const text =
        current.value.channel === 'email' && edit.subject !== ''
            ? `${edit.subject}\n\n${edit.body}`
            : edit.body;

    try {
        await navigator.clipboard.writeText(text);
        copied.value = true;
        announce(t('Copied.'));
        window.setTimeout(() => (copied.value = false), 2000);
    } catch {
        // A browser that refuses clipboard access (no permission, insecure origin) must not look
        // like a failed send. The text is on screen and selectable either way.
        announce(t('Could not copy — select the text and copy it yourself.'));
    }
}

/** Switch to one of the other variants the model offered. Costs nothing — it is already stored. */
function useVariant(body: string): void {
    edit.body = body;
}

// --- Logging -------------------------------------------------------------------------------------
const logging = ref(false);

function recordOutcome(attempt: ContactAttempt, outcome: string): void {
    router.patch(
        `/outreach/attempts/${attempt.id}`,
        { outcome },
        { preserveScroll: true },
    );
}

// --- Announcements -------------------------------------------------------------------------------
/**
 * Drafting, saving and copying all happen without the page changing visibly, so nothing else would
 * tell a screen reader that anything had happened.
 */
const announcement = ref('');

function announce(message: string): void {
    announcement.value = message;
}

const outcomeBadge = (attempt: ContactAttempt): string =>
    attempt.is_positive
        ? 'success'
        : attempt.outcome === 'opted_out' || attempt.outcome === 'bounced'
          ? 'error'
          : attempt.outcome === 'pending'
            ? 'outline'
            : 'secondary';
</script>

<template>
    <Head :title="t('Reach :company', { company: lead.company_name })" />

    <div class="flex flex-col gap-6 py-2">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Outreach') }}
                </p>
                <h1
                    class="mt-1 truncate text-2xl font-semibold text-foreground"
                >
                    {{ lead.company_name }}
                </h1>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="`/leads/${lead.id}`">
                        <ArrowLeft class="size-4 rtl:rotate-180" />
                        {{ t('Back to the lead') }}
                    </Link>
                </Button>
                <Button
                    v-if="can.work"
                    variant="outline"
                    data-test="open-log"
                    @click="logging = true"
                >
                    {{ t('Log an approach') }}
                </Button>
            </div>
        </header>

        <!-- The lead's own restriction, as a standing note rather than only as a refusal. -->
        <div
            v-if="restriction && !restriction.allows_direct_marketing"
            class="flex gap-3 rounded-2xl border border-warning/30 bg-warning/10 p-4"
            data-test="restriction-note"
        >
            <ShieldAlert
                class="mt-0.5 size-5 shrink-0 text-warning"
                aria-hidden="true"
            />
            <div class="text-sm">
                <p class="font-semibold text-warning">
                    {{ t(restriction.label) }}
                </p>
                <p class="mt-1 text-muted-foreground">
                    {{ t(restriction.guidance) }}
                </p>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <!-- The message -->
            <div class="space-y-6">
                <section
                    class="space-y-5 rounded-2xl border border-border bg-card p-6"
                >
                    <ChannelPicker
                        v-model="draft.channel"
                        :channels="options.channels"
                        :decisions="decisions"
                    />

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="space-y-2">
                            <Label for="tone">{{ t('Tone') }}</Label>
                            <NativeSelect id="tone" v-model="draft.tone">
                                <option
                                    v-for="tone in options.tones"
                                    :key="tone.value"
                                    :value="tone.value"
                                >
                                    {{ t(tone.label) }}
                                </option>
                            </NativeSelect>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    t(
                                        options.tones.find(
                                            (o) => o.value === draft.tone,
                                        )?.description ?? '',
                                    )
                                }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="locale">{{ t('Language') }}</Label>
                            <NativeSelect id="locale" v-model="draft.locale">
                                <option
                                    v-for="locale in options.locales"
                                    :key="locale.value"
                                    :value="locale.value"
                                >
                                    {{ locale.label }}
                                </option>
                            </NativeSelect>
                        </div>

                        <div class="space-y-2">
                            <Label for="template">{{ t('Brief') }}</Label>
                            <NativeSelect
                                id="template"
                                v-model="draft.template_id"
                            >
                                <option :value="null">
                                    {{ t('No brief') }}
                                </option>
                                <option
                                    v-for="template in templatesForChannel"
                                    :key="template.id"
                                    :value="template.id"
                                >
                                    {{ template.name }}
                                </option>
                            </NativeSelect>
                            <p class="text-xs text-muted-foreground">
                                <Link
                                    href="/outreach/templates"
                                    class="text-brand underline-offset-4 hover:underline"
                                >
                                    {{ t('Manage briefs') }}
                                </Link>
                            </p>
                        </div>
                    </div>

                    <div v-if="contacts.length" class="space-y-2">
                        <Label for="contact">{{ t('Writing to') }}</Label>
                        <NativeSelect
                            id="contact"
                            v-model="draft.lead_contact_id"
                            class="sm:max-w-md"
                        >
                            <option :value="null">
                                {{ t('The company, no one in particular') }}
                            </option>
                            <option
                                v-for="contact in contacts"
                                :key="contact.id"
                                :value="contact.id"
                            >
                                {{ contact.name ?? contact.email }}
                                <template v-if="contact.title">
                                    — {{ contact.title }}
                                </template>
                            </option>
                        </NativeSelect>
                        <InputError :message="draft.errors.lead_contact_id" />
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <Button
                            :disabled="draft.processing || !can.work"
                            data-test="generate"
                            @click="generate"
                        >
                            <Sparkles class="size-4" />
                            {{
                                draft.processing
                                    ? t('Writing…')
                                    : t('Write a draft')
                            }}
                        </Button>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'Alima writes it from your profile and this company’s data.',
                                )
                            }}
                        </p>
                    </div>
                    <InputError :message="draft.errors.channel" />
                </section>

                <!-- The draft itself -->
                <section
                    v-if="current"
                    class="space-y-4 rounded-2xl border border-lime bg-card p-6"
                    data-test="draft"
                >
                    <header
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge variant="lime">{{
                                t(current.channel_label)
                            }}</Badge>
                            <Badge variant="outline">{{
                                t(current.tone_label)
                            }}</Badge>
                            <Badge
                                :variant="
                                    current.status === 'failed'
                                        ? 'error'
                                        : current.status === 'draft'
                                          ? 'outline'
                                          : 'success'
                                "
                            >
                                {{ t(current.status_label) }}
                            </Badge>
                        </div>
                        <p class="font-mono text-xs text-muted-foreground">
                            {{ when(current.created_at) }}
                        </p>
                    </header>

                    <p
                        v-if="current.error_message"
                        class="rounded-xl border border-destructive/30 bg-destructive/10 p-3 text-xs text-destructive"
                        data-test="send-error"
                    >
                        {{ current.error_message }}
                    </p>

                    <div v-if="current.channel === 'email'" class="space-y-2">
                        <Label for="subject">{{ t('Subject') }}</Label>
                        <Input
                            id="subject"
                            v-model="edit.subject"
                            :disabled="current.is_spent"
                            dir="auto"
                        />
                        <InputError :message="edit.errors.subject" />
                    </div>

                    <div class="space-y-2">
                        <Label for="body">{{ t('Message') }}</Label>
                        <Textarea
                            id="body"
                            v-model="edit.body"
                            :rows="current.channel === 'call' ? 14 : 10"
                            :disabled="current.is_spent"
                            dir="auto"
                            data-test="body"
                        />
                        <InputError :message="edit.errors.body" />
                    </div>

                    <!-- The other angles the model offered. Already stored, so switching is free. -->
                    <div v-if="current.variants.length > 1" class="space-y-2">
                        <p
                            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                        >
                            {{ t('Other angles') }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-for="(variant, index) in current.variants"
                                :key="index"
                                type="button"
                                size="sm"
                                variant="outline"
                                :disabled="current.is_spent"
                                @click="useVariant(variant)"
                            >
                                {{ t('Angle :n', { n: index + 1 }) }}
                            </Button>
                        </div>
                    </div>

                    <footer
                        class="flex flex-wrap items-center gap-2 border-t border-border pt-4"
                    >
                        <!-- Copy is equal to Send, not a fallback: for LinkedIn and call scripts
                             it is the only way the message goes anywhere. -->
                        <Button
                            variant="outline"
                            data-test="copy"
                            @click="copy"
                        >
                            <component
                                :is="copied ? Check : ClipboardCopy"
                                class="size-4"
                            />
                            {{ copied ? t('Copied') : t('Copy') }}
                        </Button>

                        <Button
                            v-if="isDirty && !current.is_spent"
                            variant="brand"
                            :disabled="edit.processing"
                            data-test="save"
                            @click="save"
                        >
                            {{ t('Save changes') }}
                        </Button>

                        <Button
                            v-if="current.can_send"
                            :disabled="isDirty"
                            :title="
                                isDirty
                                    ? t('Save your changes first.')
                                    : undefined
                            "
                            data-test="send"
                            @click="send"
                        >
                            <Send class="size-4 rtl:rotate-180" />
                            {{ t('Send email') }}
                        </Button>

                        <Button
                            v-if="
                                !current.is_spent && current.channel !== 'email'
                            "
                            variant="outline"
                            @click="logging = true"
                        >
                            {{ t('Mark as sent') }}
                        </Button>

                        <Button
                            v-if="current.can_delete"
                            variant="ghost"
                            size="icon-sm"
                            class="ms-auto"
                            :aria-label="t('Delete draft')"
                            @click="remove(current)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </footer>
                </section>

                <EmptyState
                    v-else
                    :icon="Sparkles"
                    :title="t('No drafts yet')"
                    :description="
                        t(
                            'Pick a channel and a tone, and Alima will write something you can edit.',
                        )
                    "
                    data-test="no-drafts"
                />

                <!-- Earlier drafts, so a member can go back to one they preferred. -->
                <section v-if="messages.length > 1" class="space-y-2">
                    <p
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Earlier drafts') }}
                    </p>
                    <ul class="space-y-1">
                        <li
                            v-for="message in messages"
                            :key="message.id"
                            class="flex items-center gap-3"
                        >
                            <Button
                                size="sm"
                                :variant="
                                    current?.id === message.id
                                        ? 'secondary'
                                        : 'ghost'
                                "
                                class="justify-start"
                                @click="selectedMessageId = message.id"
                            >
                                {{ t(message.channel_label) }} ·
                                {{ t(message.status_label) }} ·
                                {{ when(message.created_at) }}
                            </Button>
                        </li>
                    </ul>
                </section>
            </div>

            <!-- The evidence -->
            <aside class="space-y-6">
                <section class="rounded-2xl border border-border bg-card p-5">
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Contacts') }}
                    </h2>

                    <ul v-if="contacts.length" class="mt-3 space-y-3">
                        <li
                            v-for="contact in contacts"
                            :key="contact.id"
                            class="text-sm"
                        >
                            <p class="font-medium text-foreground">
                                {{ contact.name ?? t('Unnamed') }}
                            </p>
                            <p
                                v-if="contact.title"
                                class="text-xs text-muted-foreground"
                            >
                                {{ contact.title }}
                            </p>
                            <p
                                v-if="contact.email"
                                class="font-mono text-xs break-all text-muted-foreground"
                                dir="ltr"
                            >
                                {{ contact.email }}
                            </p>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <Badge
                                    v-if="contact.is_role_based"
                                    variant="outline"
                                >
                                    {{ t('Role mailbox') }}
                                </Badge>
                                <!-- Flagged rather than hidden: the platform prefers role-based
                                     data and the team is the controller for the choice
                                     (docs/compliance.md §2). -->
                                <Badge
                                    v-if="contact.is_personal"
                                    variant="warning"
                                >
                                    {{ t('Looks personal') }}
                                </Badge>
                            </div>
                        </li>
                    </ul>

                    <p v-else class="mt-3 text-sm text-muted-foreground">
                        {{
                            t(
                                'No contacts yet. Enrich the lead or add one by hand.',
                            )
                        }}
                    </p>
                </section>

                <section class="rounded-2xl border border-border bg-card p-5">
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('What we have tried') }}
                    </h2>

                    <ul
                        v-if="attempts.length"
                        class="mt-3 space-y-4"
                        data-test="attempts"
                    >
                        <li
                            v-for="attempt in attempts"
                            :key="attempt.id"
                            class="text-sm"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge variant="outline">
                                    {{ t(attempt.channel_label) }}
                                </Badge>
                                <Badge
                                    :variant="outcomeBadge(attempt) as never"
                                >
                                    {{ t(attempt.outcome_label) }}
                                </Badge>
                            </div>
                            <p
                                class="mt-1 font-mono text-xs text-muted-foreground"
                            >
                                {{ when(attempt.occurred_at) }}
                                <template v-if="attempt.author">
                                    · {{ attempt.author.name }}
                                </template>
                            </p>
                            <p
                                v-if="attempt.notes"
                                class="mt-1 text-xs whitespace-pre-line text-muted-foreground"
                                dir="auto"
                            >
                                {{ attempt.notes }}
                            </p>

                            <!-- The answer usually arrives days later, so it is editable here
                                 rather than only at the moment of logging. -->
                            <NativeSelect
                                v-if="can.work"
                                :model-value="attempt.outcome"
                                class="mt-2 h-8 text-xs"
                                :aria-label="t('How it went')"
                                @update:model-value="
                                    recordOutcome(attempt, String($event))
                                "
                            >
                                <option
                                    v-for="outcome in options.outcomes"
                                    :key="outcome.value"
                                    :value="outcome.value"
                                >
                                    {{ t(outcome.label) }}
                                </option>
                            </NativeSelect>
                        </li>
                    </ul>

                    <p v-else class="mt-3 text-sm text-muted-foreground">
                        {{ t('Nobody has approached this company yet.') }}
                    </p>
                </section>
            </aside>
        </div>

        <p class="sr-only" role="status" aria-live="polite">
            {{ announcement }}
        </p>

        <LogAttemptDialog
            v-model:open="logging"
            :lead-id="lead.id"
            :channels="options.channels"
            :outcomes="options.outcomes"
            :contacts="contacts"
            :channel="current?.channel ?? null"
            :contact-id="current?.lead_contact_id ?? selectedContactId"
            :message-id="current && !current.is_spent ? current.id : null"
        />
    </div>
</template>
