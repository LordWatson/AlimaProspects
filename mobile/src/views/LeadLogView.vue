<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    IonBackButton,
    IonButton,
    IonButtons,
    IonContent,
    IonHeader,
    IonPage,
    IonSpinner,
    IonTextarea,
    IonTitle,
    IonToolbar,
} from '@ionic/vue';
import type { ApiResource } from '@shared/api';
import type {
    ContactOutcome,
    Lead,
    LeadContact,
    OutreachChannel,
} from '@shared/types';
import { api } from '@/lib/api';
import { t } from '@/lib/i18n';
import { notifyFailure, notifySuccess } from '@/lib/feedback';
import StateMessage from '@/components/StateMessage.vue';

/*
 * Logging an approach that happened away from the app — the call in the car park, the chat at the
 * stand. This is the screen that justifies a mobile app at all: the member is standing up with a
 * phone, and a contact attempt recorded two days later from a desk is one the outreach history
 * has already lost.
 *
 * Two outcomes **suppress the contact**: `not_interested` and `opted_out` add them to the
 * do-not-contact list, which is irreversible from here and is the whole point of the list
 * (`docs/compliance.md` §3.2). So the screen says so before the tap rather than after it — an
 * accidental "not interested" is a prospect the team may never approach again.
 *
 * `occurred_at` is deliberately not offered. The server defaults it to now, it will not accept a
 * future date, and this screen exists for the approach that just happened; backdating last week's
 * call belongs on the web screen with a date picker and room for the caveat.
 */
const route = useRoute();
const router = useRouter();
const leadId = Number(route.params.id);

const lead = ref<Lead | null>(null);
const contacts = ref<LeadContact[]>([]);
const state = ref<'loading' | 'ready' | 'error'>('loading');
const busy = ref(false);

const channel = ref<OutreachChannel>('call');
const outcome = ref<ContactOutcome>('no_answer');
const contactId = ref<number | null>(null);
const notes = ref('');

const channels: { value: OutreachChannel; label: string }[] = [
    { value: 'call', label: 'Call' },
    { value: 'email', label: 'Email' },
    { value: 'linkedin', label: 'LinkedIn' },
];

/**
 * The outcomes worth offering from a phone, with the two that suppress marked.
 *
 * `suppresses` is also sent by the server on its own option list; it is restated here because
 * this screen has to warn *before* the tap, and the warning is this app's wording.
 */
const outcomes: {
    value: ContactOutcome;
    label: string;
    suppresses: boolean;
}[] = [
    { value: 'no_answer', label: 'No answer', suppresses: false },
    { value: 'replied', label: 'Replied', suppresses: false },
    { value: 'interested', label: 'Interested', suppresses: false },
    { value: 'meeting_booked', label: 'Meeting booked', suppresses: false },
    { value: 'not_interested', label: 'Not interested', suppresses: true },
    { value: 'opted_out', label: 'Opted out', suppresses: true },
];

const suppressing = computed(
    () =>
        outcomes.find((option) => option.value === outcome.value)
            ?.suppresses === true,
);

async function load(): Promise<void> {
    try {
        const detail = await api.get<ApiResource<{ lead: Lead }>>(
            `leads/${leadId}`,
        );

        lead.value = detail.data.lead;
        contacts.value = detail.data.lead.contacts ?? [];
        contactId.value = contacts.value[0]?.id ?? null;
        state.value = 'ready';
    } catch {
        state.value = 'error';
    }
}

async function submit(): Promise<void> {
    if (busy.value) {
        return;
    }

    busy.value = true;

    try {
        await api.post(`leads/${leadId}/outreach/attempts`, {
            channel: channel.value,
            outcome: outcome.value,
            lead_contact_id: contactId.value,
            ...(notes.value.trim() === '' ? {} : { notes: notes.value.trim() }),
        });

        await notifySuccess(t('Logged.'));
        await router.replace(`/leads/${leadId}`);
    } catch (error) {
        await notifyFailure(error, t('We could not log that approach.'));
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <IonPage>
        <IonHeader>
            <IonToolbar>
                <IonButtons slot="start">
                    <IonBackButton
                        :text="t('Back')"
                        :default-href="`/leads/${leadId}`"
                    />
                </IonButtons>
                <IonTitle>{{ t('Log an approach') }}</IonTitle>
            </IonToolbar>
        </IonHeader>

        <IonContent :fullscreen="true">
            <StateMessage v-if="state === 'loading'" state="loading" />

            <StateMessage
                v-else-if="state === 'error'"
                state="error"
                :message="t('We could not load that lead.')"
                @retry="load"
            />

            <div v-else class="alima-page alima-stack">
                <header>
                    <p class="alima-eyebrow">{{ t('Log an approach') }}</p>
                    <h1 class="alima-h1">{{ lead?.company_name }}</h1>
                </header>

                <section class="alima-card">
                    <p class="alima-eyebrow">{{ t('Channel') }}</p>
                    <div
                        class="alima-choices log__choices"
                        role="group"
                        :aria-label="t('Channel')"
                    >
                        <button
                            v-for="option in channels"
                            :key="option.value"
                            type="button"
                            class="alima-choice"
                            :aria-pressed="option.value === channel"
                            :disabled="busy"
                            @click="channel = option.value"
                        >
                            {{ t(option.label) }}
                        </button>
                    </div>

                    <p class="alima-eyebrow log__label">{{ t('Outcome') }}</p>
                    <div
                        class="alima-choices log__choices"
                        role="group"
                        :aria-label="t('Outcome')"
                    >
                        <button
                            v-for="option in outcomes"
                            :key="option.value"
                            type="button"
                            class="alima-choice"
                            :class="{
                                'alima-choice--grave': option.suppresses,
                            }"
                            :aria-pressed="option.value === outcome"
                            :disabled="busy"
                            @click="outcome = option.value"
                        >
                            {{ t(option.label) }}
                        </button>
                    </div>

                    <p v-if="suppressing" class="log__warning">
                        {{
                            t(
                                'This adds the contact to your do-not-contact list. Alima will not approach them again, on any channel.',
                            )
                        }}
                    </p>

                    <template v-if="contacts.length > 0">
                        <label
                            class="alima-eyebrow log__label"
                            for="log-contact"
                        >
                            {{ t('Contact') }}
                        </label>
                        <select
                            id="log-contact"
                            v-model="contactId"
                            class="alima-select log__select"
                            :disabled="busy"
                        >
                            <option
                                v-for="contact in contacts"
                                :key="contact.id"
                                :value="contact.id"
                            >
                                {{
                                    contact.name ??
                                    contact.email ??
                                    contact.phone
                                }}
                            </option>
                        </select>
                    </template>

                    <IonTextarea
                        v-model="notes"
                        :auto-grow="true"
                        :rows="3"
                        fill="outline"
                        label-placement="stacked"
                        :label="t('Note (optional)')"
                        :placeholder="t('What did you agree?')"
                        :disabled="busy"
                        class="log__notes"
                    />

                    <IonButton
                        expand="block"
                        :color="suppressing ? 'danger' : 'primary'"
                        class="log__action"
                        :disabled="busy"
                        @click="submit"
                    >
                        <IonSpinner v-if="busy" name="crescent" />
                        <span v-else>{{ t('Save') }}</span>
                    </IonButton>
                </section>
            </div>
        </IonContent>
    </IonPage>
</template>

<style scoped>
.log__choices {
    margin-top: 6px;
}

.log__label {
    margin-top: 16px;
}

.log__warning {
    margin: 12px 0 0;
    padding: 10px 12px;
    border-radius: var(--alima-radius-sm);
    background: var(--alima-cream-sunken);
    border-inline-start: 3px solid var(--alima-status-error);
    font-size: 0.8125rem;
    line-height: 1.5;
    color: var(--alima-text-secondary);
}

.log__select {
    margin-top: 4px;
}

.log__notes {
    margin-top: 16px;
}

.log__action {
    margin-top: 16px;
}
</style>
