<script setup lang="ts">
import { ref } from 'vue';
import { IonButton } from '@ionic/vue';
import type { ApiResource } from '@shared/api';
import type { LeadContact } from '@shared/types';
import { api } from '@/lib/api';
import { notifyFailure } from '@/lib/feedback';
import { t } from '@/lib/i18n';

/*
 * The lead's contacts, on a phone (PL3).
 *
 * The section the app exists for: a member standing outside a building wants the number now, and
 * every other screen here is in service of getting them to this one.
 *
 * A value a paid vendor produced arrives **masked** with a Reveal button; one the free-tier
 * extraction read off a public page does not (ADR 0029). Once it is real, the three things to do
 * with it are one tap each — and WhatsApp **only for a mobile**, because a `wa.me` link on a
 * landline opens a chat that can never be delivered, which is worse than no link: it looks like it
 * worked.
 *
 * Its own component rather than more of `LeadDetailView`, which was already at the 300-line limit
 * the mobile lint enforces — and the same split the web made with `RevealableValue`.
 */
const props = defineProps<{
    leadId: number;
    contacts: LeadContact[];
}>();

/** The parent owns the lead, so a revealed contact is handed back rather than mutated here. */
const emit = defineEmits<{ revealed: [contact: LeadContact] }>();

/** Which contact is mid-reveal, so only its own button is disabled. */
const revealing = ref<number | null>(null);

async function reveal(
    contact: LeadContact,
    kind: 'email' | 'phone',
): Promise<void> {
    revealing.value = contact.id;

    try {
        const path = kind === 'email' ? 'reveal-email' : 'reveal-phone';

        // No leading slash and an explicit empty body: this client prefixes the API base itself,
        // and it takes a body like every other call.
        const response = await api.post<ApiResource<LeadContact>>(
            `leads/${props.leadId}/contacts/${contact.id}/${path}`,
            {},
        );

        emit('revealed', response.data);
    } catch (error) {
        // The error is passed rather than swallowed: `notifyFailure` reads an `ApiError` for the
        // server's own message, and here that is the message that matters — "you have used this
        // month's reveals" is a different thing to do about it than "Alima is unreachable".
        await notifyFailure(error, t('Alima could not show that just now.'));
    } finally {
        revealing.value = null;
    }
}

/**
 * WhatsApp wants the number with no `+` and no punctuation. Built from the E.164 form the server
 * sends, so a number it could not normalise has no link at all rather than a broken one.
 */
function whatsAppLink(phone: string | null): string {
    return `https://wa.me/${(phone ?? '').replaceAll(/\D+/gu, '')}`;
}
</script>

<template>
    <section v-if="contacts.length > 0" class="alima-card">
        <div class="alima-section-title">
            <h2 class="alima-h2">{{ t('Contacts') }}</h2>
        </div>

        <ul class="contacts">
            <li v-for="contact in contacts" :key="contact.id">
                <p class="contacts__name" dir="auto">
                    {{ contact.name ?? t('Unnamed contact') }}
                </p>
                <p
                    v-if="contact.title"
                    class="alima-muted contacts__title"
                    dir="auto"
                >
                    {{ contact.title }}
                </p>

                <div v-if="contact.email" class="contacts__row">
                    <span
                        v-if="contact.requires_email_reveal"
                        class="alima-muted alima-ltr"
                    >
                        {{ contact.email }}
                    </span>
                    <a
                        v-else
                        :href="`mailto:${contact.email}`"
                        class="contacts__link alima-ltr"
                    >
                        {{ contact.email }}
                    </a>
                    <IonButton
                        v-if="contact.requires_email_reveal"
                        size="small"
                        fill="outline"
                        :disabled="revealing === contact.id"
                        @click="reveal(contact, 'email')"
                    >
                        {{ t('Show (1 credit)') }}
                    </IonButton>
                </div>

                <div v-if="contact.has_phone" class="contacts__row">
                    <span
                        v-if="contact.requires_phone_reveal"
                        class="alima-muted alima-ltr"
                    >
                        {{ contact.phone }}
                    </span>
                    <template v-else>
                        <a
                            :href="`tel:${contact.phone}`"
                            class="contacts__link alima-ltr"
                        >
                            {{ contact.phone }}
                        </a>
                        <a
                            v-if="contact.phone_accepts_messaging"
                            :href="whatsAppLink(contact.phone)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="contacts__link"
                        >
                            {{ t('WhatsApp') }}
                        </a>
                    </template>
                    <IonButton
                        v-if="contact.requires_phone_reveal"
                        size="small"
                        fill="outline"
                        :disabled="revealing === contact.id"
                        @click="reveal(contact, 'phone')"
                    >
                        {{ t('Show (1 credit)') }}
                    </IonButton>
                </div>
            </li>
        </ul>
    </section>
</template>

<style scoped>
.contacts {
    display: flex;
    flex-direction: column;
    gap: 16px;
    margin: 8px 0 0;
    padding: 0;
    list-style: none;
}

.contacts__name {
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--alima-text-primary);
}

.contacts__title {
    font-size: 0.8125rem;
}

.contacts__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-top: 4px;
    font-size: 0.875rem;
}

.contacts__link {
    color: var(--alima-brand);
    text-decoration: none;
}
</style>
