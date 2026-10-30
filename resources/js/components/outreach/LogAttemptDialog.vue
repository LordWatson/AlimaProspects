<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import type {
    ContactOutcomeOption,
    LogAttemptForm,
    OutreachChannel,
    OutreachChannelOption,
} from '@/types';
import type { LeadContact } from '@/types';

/*
 * Record that the team approached this company — the LinkedIn message they pasted, the call they
 * made, the email they sent from their own mailbox.
 *
 * The one thing this dialog must get right is the **opt-out warning**. Choosing "Asked us to stop"
 * adds the person to the do-not-contact list for every team on the platform, immediately and
 * irreversibly, and the member has to know that before they press the button — not discover it
 * afterwards. The warning uses `status.warning`, never Signal Orange (brand-tokens.md §2.1).
 */
const props = defineProps<{
    open: boolean;
    leadId: number;
    channels: OutreachChannelOption[];
    outcomes: ContactOutcomeOption[];
    contacts: LeadContact[];
    /** Pre-selected when the dialog is opened from a specific draft. */
    channel?: OutreachChannel | null;
    contactId?: number | null;
    messageId?: number | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'logged'): void;
}>();

const { t } = useI18n();

/** `datetime-local` wants the member's own clock, which the server then normalises to UTC. */
function localNow(): string {
    const now = new Date();
    const offset = now.getTimezoneOffset() * 60_000;

    return new Date(now.getTime() - offset).toISOString().slice(0, 16);
}

const form = useForm<LogAttemptForm>({
    channel: props.channel ?? 'call',
    outcome: 'pending',
    occurred_at: localNow(),
    lead_contact_id: props.contactId ?? null,
    message_id: props.messageId ?? null,
    notes: '',
});

// Re-seeded each time it opens, so logging a second attempt doesn't inherit the first's notes.
watch(
    () => props.open,
    (open) => {
        if (!open) return;

        form.defaults({
            channel: props.channel ?? 'call',
            outcome: 'pending',
            occurred_at: localNow(),
            lead_contact_id: props.contactId ?? null,
            message_id: props.messageId ?? null,
            notes: '',
        });
        form.reset();
        form.clearErrors();
    },
);

const chosenOutcome = computed(() =>
    props.outcomes.find((outcome) => outcome.value === form.outcome),
);

function submit(): void {
    form.post(`/leads/${props.leadId}/outreach/log`, {
        preserveScroll: true,
        onSuccess: () => {
            emit('update:open', false);
            emit('logged');
        },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ t('Log an approach') }}</DialogTitle>
                <DialogDescription>
                    {{
                        t(
                            'Keeps the lead’s history straight — what you tried, when, and how it went.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="log-channel">{{ t('Channel') }}</Label>
                        <NativeSelect id="log-channel" v-model="form.channel">
                            <option
                                v-for="channel in channels"
                                :key="channel.value"
                                :value="channel.value"
                            >
                                {{ t(channel.label) }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.channel" />
                    </div>

                    <div class="space-y-2">
                        <Label for="log-when">{{ t('When') }}</Label>
                        <Input
                            id="log-when"
                            v-model="form.occurred_at"
                            type="datetime-local"
                            dir="ltr"
                        />
                        <!-- Logging yesterday's call today is the normal case, not an exception. -->
                        <p class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'You can log something that already happened.',
                                )
                            }}
                        </p>
                        <InputError :message="form.errors.occurred_at" />
                    </div>
                </div>

                <div v-if="contacts.length" class="space-y-2">
                    <Label for="log-contact">{{ t('Who you spoke to') }}</Label>
                    <NativeSelect
                        id="log-contact"
                        v-model="form.lead_contact_id"
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
                    <InputError :message="form.errors.lead_contact_id" />
                </div>

                <div class="space-y-2">
                    <Label for="log-outcome">{{ t('How it went') }}</Label>
                    <NativeSelect id="log-outcome" v-model="form.outcome">
                        <option
                            v-for="outcome in outcomes"
                            :key="outcome.value"
                            :value="outcome.value"
                        >
                            {{ t(outcome.label) }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.outcome" />
                </div>

                <!-- Said before the button is pressed, because it cannot be undone. -->
                <p
                    v-if="chosenOutcome?.suppresses"
                    class="flex gap-2 rounded-xl border border-warning/30 bg-warning/10 p-3 text-xs text-warning"
                    data-test="suppression-warning"
                >
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <span v-if="chosenOutcome.is_global">
                        {{
                            t(
                                'This adds them to the do-not-contact list for every team on Alima, straight away. It cannot be undone.',
                            )
                        }}
                    </span>
                    <span v-else>
                        {{
                            t(
                                'This stops your team contacting that address. You can lift it later from your do-not-contact list.',
                            )
                        }}
                    </span>
                </p>

                <div class="space-y-2">
                    <Label for="log-notes">{{ t('Notes') }}</Label>
                    <Textarea
                        id="log-notes"
                        v-model="form.notes"
                        rows="3"
                        dir="auto"
                        :placeholder="
                            t('What was said, and what happens next.')
                        "
                    />
                    <InputError :message="form.errors.notes" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                    >
                        {{ t('Cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="log-submit"
                    >
                        {{ t('Log it') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
