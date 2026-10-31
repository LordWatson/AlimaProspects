<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    IonBackButton,
    IonButton,
    IonButtons,
    IonContent,
    IonHeader,
    IonInput,
    IonPage,
    IonSpinner,
    IonTextarea,
    IonTitle,
    IonToolbar,
} from '@ionic/vue';
import type { ApiResource } from '@shared/api';
import type {
    Lead,
    LeadContact,
    OutreachMessage,
    OutreachTone,
    SupportedLocale,
} from '@shared/types';
import { api } from '@/lib/api';
import { currentLocale, t } from '@/lib/i18n';
import { notifyFailure, notifySuccess } from '@/lib/feedback';
import StateMessage from '@/components/StateMessage.vue';

/*
 * An AI draft for one lead, then sending it (`POST /leads/{lead}/outreach/draft`, then
 * `POST /outreach/messages/{message}/send`).
 *
 * The model is asked for the draft on the server, where the prompt, the credit accounting and the
 * compliance guard all live — this screen chooses the tone, the language and the contact, and
 * shows what came back.
 *
 * Three things the API's shape obliges and the screen honours:
 *   - **The draft is editable before it sends.** `PATCH /outreach/messages/{message}` exists
 *     precisely because a generated message that cannot be corrected is one nobody will send.
 *   - **Variants cost nothing to switch between.** The model offers several and they all arrive
 *     in one response, so flicking between them is local rather than another AI request.
 *   - **Sending is a separate, explicit act.** Drafting never sends, and `is_spent` means a
 *     message has already been used and is not re-sendable.
 *
 * The locale defaults to the app's own but is a **choice**: a bilingual team drafts Arabic from
 * an English interface all day.
 */
const route = useRoute();
const router = useRouter();
const leadId = Number(route.params.id);

const lead = ref<Lead | null>(null);
const contacts = ref<LeadContact[]>([]);
const message = ref<OutreachMessage | null>(null);

const state = ref<'loading' | 'ready' | 'error'>('loading');
const drafting = ref(false);
const sending = ref(false);
const saving = ref(false);

const tone = ref<OutreachTone>('warm');
const locale = ref<SupportedLocale>(currentLocale.value);
const contactId = ref<number | null>(null);
const variant = ref(0);

const subject = ref('');
const body = ref('');

const tones: { value: OutreachTone; label: string }[] = [
    { value: 'warm', label: 'Warm' },
    { value: 'direct', label: 'Direct' },
    { value: 'formal', label: 'Formal' },
    { value: 'consultative', label: 'Consultative' },
];

/** A contact with no email cannot receive an email, whatever the guard says about the channel. */
const emailable = computed(() =>
    contacts.value.filter((contact) => (contact.email ?? '') !== ''),
);

const dirty = computed(
    () =>
        message.value !== null &&
        (subject.value !== (message.value.subject ?? '') ||
            body.value !== message.value.body),
);

async function load(): Promise<void> {
    try {
        const detail = await api.get<ApiResource<{ lead: Lead }>>(
            `leads/${leadId}`,
        );

        lead.value = detail.data.lead;
        contacts.value = detail.data.lead.contacts ?? [];
        contactId.value = emailable.value[0]?.id ?? null;
        state.value = 'ready';
    } catch {
        state.value = 'error';
    }
}

async function draft(): Promise<void> {
    if (drafting.value) {
        return;
    }

    drafting.value = true;

    try {
        const response = await api.post<ApiResource<OutreachMessage>>(
            `leads/${leadId}/outreach/draft`,
            {
                channel: 'email',
                tone: tone.value,
                locale: locale.value,
                lead_contact_id: contactId.value,
            },
        );

        message.value = response.data;
        variant.value = 0;
        subject.value = response.data.subject ?? '';
        body.value = response.data.body;
        await notifySuccess(t('Draft ready.'));
    } catch (error) {
        // Includes the money-spending failures the guards raise with their own codes — an
        // exhausted AI credit allowance is a domain error, not a throttle (ADR 0012), and its
        // message is the one to show.
        await notifyFailure(error, t('We could not write that draft.'));
    } finally {
        drafting.value = false;
    }
}

function useVariant(index: number): void {
    const variants = message.value?.variants ?? [];
    const next = variants[index];

    if (next === undefined) {
        return;
    }

    variant.value = index;
    body.value = next;
}

async function save(): Promise<void> {
    if (message.value === null || saving.value) {
        return;
    }

    saving.value = true;

    try {
        const response = await api.patch<ApiResource<OutreachMessage>>(
            `outreach/messages/${message.value.id}`,
            { subject: subject.value, body: body.value },
        );

        message.value = response.data;
        await notifySuccess(t('Saved.'));
    } catch (error) {
        await notifyFailure(error, t('We could not save that draft.'));
    } finally {
        saving.value = false;
    }
}

async function send(): Promise<void> {
    if (message.value === null || sending.value) {
        return;
    }

    // Save first, so what sends is what is on screen. Anything else and an edit made and not
    // saved is silently dropped at the one moment it matters.
    if (dirty.value) {
        await save();
    }

    sending.value = true;

    try {
        await api.post(`outreach/messages/${message.value.id}/send`);
        await notifySuccess(t('Sent.'));
        await router.replace(`/leads/${leadId}`);
    } catch (error) {
        await notifyFailure(error, t('We could not send that message.'));
    } finally {
        sending.value = false;
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
                <IonTitle>{{ t('Draft a message') }}</IonTitle>
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
                    <p class="alima-eyebrow">{{ t('Email') }}</p>
                    <h1 class="alima-h1">{{ lead?.company_name }}</h1>
                </header>

                <StateMessage
                    v-if="emailable.length === 0"
                    state="empty"
                    :message="t('No email address on this lead')"
                    :hint="
                        t(
                            'Add a contact with an email address on the Alima website, then come back.',
                        )
                    "
                />

                <template v-else>
                    <section class="alima-card">
                        <div class="alima-section-title">
                            <h2 class="alima-h2">{{ t('Who, and how') }}</h2>
                        </div>

                        <label class="alima-eyebrow" for="draft-contact">
                            {{ t('Contact') }}
                        </label>
                        <select
                            id="draft-contact"
                            v-model="contactId"
                            class="alima-select draft__select"
                            :disabled="drafting"
                        >
                            <option
                                v-for="contact in emailable"
                                :key="contact.id"
                                :value="contact.id"
                            >
                                {{ contact.name ?? contact.email }}
                                <template v-if="contact.title">
                                    — {{ contact.title }}</template
                                >
                            </option>
                        </select>

                        <p class="alima-eyebrow draft__label">
                            {{ t('Tone') }}
                        </p>
                        <div
                            class="alima-choices"
                            role="group"
                            :aria-label="t('Tone')"
                        >
                            <button
                                v-for="option in tones"
                                :key="option.value"
                                type="button"
                                class="alima-choice"
                                :aria-pressed="option.value === tone"
                                :disabled="drafting"
                                @click="tone = option.value"
                            >
                                {{ t(option.label) }}
                            </button>
                        </div>

                        <p class="alima-eyebrow draft__label">
                            {{ t('Language') }}
                        </p>
                        <!-- Each language names itself, so neither label goes through
                             `t()`: a member looking for Arabic looks for العربية. -->
                        <div
                            class="alima-choices"
                            role="group"
                            :aria-label="t('Language')"
                        >
                            <button
                                type="button"
                                class="alima-choice"
                                lang="en"
                                :aria-pressed="locale === 'en'"
                                :disabled="drafting"
                                @click="locale = 'en'"
                            >
                                English
                            </button>
                            <button
                                type="button"
                                class="alima-choice"
                                lang="ar"
                                :aria-pressed="locale === 'ar'"
                                :disabled="drafting"
                                @click="locale = 'ar'"
                            >
                                العربية
                            </button>
                        </div>

                        <IonButton
                            expand="block"
                            color="secondary"
                            class="draft__action"
                            :disabled="drafting || contactId === null"
                            @click="draft"
                        >
                            <IonSpinner v-if="drafting" name="crescent" />
                            <span v-else>
                                {{
                                    message === null
                                        ? t('Write a draft')
                                        : t('Write another')
                                }}
                            </span>
                        </IonButton>
                    </section>

                    <section v-if="message" class="alima-card">
                        <div class="alima-section-title">
                            <h2 class="alima-h2">{{ t('Drafted message') }}</h2>
                            <span class="alima-eyebrow">{{
                                t(message.status_label)
                            }}</span>
                        </div>

                        <IonInput
                            v-model="subject"
                            fill="outline"
                            label-placement="stacked"
                            :label="t('Subject')"
                            :disabled="sending"
                        />

                        <IonTextarea
                            v-model="body"
                            :auto-grow="true"
                            :rows="10"
                            fill="outline"
                            label-placement="stacked"
                            :label="t('Message')"
                            :disabled="sending"
                            class="draft__body"
                            :dir="locale === 'ar' ? 'rtl' : 'ltr'"
                        />

                        <!-- Every variant the model offered arrived with the draft, so switching
                             between them costs nothing — no second AI request, no second charge. -->
                        <template v-if="message.variants.length > 1">
                            <p class="alima-eyebrow draft__label">
                                {{ t('Variants') }}
                            </p>
                            <div
                                class="alima-choices"
                                role="group"
                                :aria-label="t('Variants')"
                            >
                                <button
                                    v-for="(_, index) in message.variants"
                                    :key="index"
                                    type="button"
                                    class="alima-choice"
                                    :aria-pressed="index === variant"
                                    @click="useVariant(index)"
                                >
                                    {{ index + 1 }}
                                </button>
                            </div>
                        </template>

                        <IonButton
                            v-if="dirty"
                            expand="block"
                            fill="outline"
                            color="primary"
                            class="draft__action"
                            :disabled="saving || sending"
                            @click="save"
                        >
                            {{ t('Save draft') }}
                        </IonButton>

                        <IonButton
                            expand="block"
                            color="primary"
                            class="draft__action"
                            :disabled="sending || !message.can_send"
                            @click="send"
                        >
                            <IonSpinner v-if="sending" name="crescent" />
                            <span v-else>{{ t('Send email') }}</span>
                        </IonButton>

                        <p v-if="!message.can_send" class="draft__note">
                            {{
                                message.is_spent
                                    ? t('This message has already been used.')
                                    : t('Sending is switched off')
                            }}
                        </p>
                    </section>
                </template>
            </div>
        </IonContent>
    </IonPage>
</template>

<style scoped>
.draft__select {
    margin-top: 4px;
}

.draft__label {
    margin: 16px 0 6px;
}

.draft__action {
    margin-top: 16px;
}

.draft__body {
    margin-top: 12px;
}

.draft__note {
    margin: 8px 0 0;
    font-size: 0.8125rem;
    color: var(--alima-text-muted);
}
</style>
