<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import {
    IonBackButton,
    IonButton,
    IonButtons,
    IonContent,
    IonHeader,
    IonIcon,
    IonInput,
    IonPage,
    IonTextarea,
    IonTitle,
    IonToolbar,
    onIonViewWillEnter,
} from '@ionic/vue';
import {
    callOutline,
    chatbubbleEllipsesOutline,
    checkmarkCircleOutline,
    documentTextOutline,
} from 'ionicons/icons';
import type { ApiResource } from '@shared/api';
import type {
    LeadActivity,
    LeadScore,
    Lead,
    OutreachDecision,
    PipelineStage,
} from '@shared/types';
import { api } from '@/lib/api';
import { t } from '@/lib/i18n';
import { formatRelative } from '@/lib/format';
import { notifyFailure, notifySuccess } from '@/lib/feedback';
import GradeBadge from '@/components/GradeBadge.vue';
import StateMessage from '@/components/StateMessage.vue';

/*
 * One lead: who they are, how well they fit, what the team has done, and what to do next.
 *
 * `GET /leads/{lead}` answers with the lead, its score and its timeline in **one** payload, by
 * design — three round trips over a phone connection is three chances to half-load a screen
 * (`LeadDetailResource`). The two extra requests this screen does make are for things the lead
 * itself cannot know: the team's pipeline columns, and which channels the compliance guard will
 * allow.
 *
 * The channel decisions are fetched **before** anything is offered, not after it is attempted.
 * Whether a prospect may be approached is `OutreachGuard`'s answer and not a permission check
 * (CLAUDE.md §2), and a compose button that opens and then refuses is the wrong order: the
 * sentence a member needs is "this one may not be emailed", before they have written anything.
 */
const route = useRoute();
const leadId = Number(route.params.id);

const lead = ref<Lead | null>(null);
const score = ref<LeadScore | null>(null);
const activities = ref<LeadActivity[]>([]);
const stages = ref<PipelineStage[]>([]);
const decisions = ref<OutreachDecision[]>([]);

const state = ref<'loading' | 'ready' | 'error'>('loading');
const busy = ref(false);

const note = ref('');
const taskTitle = ref('');
const taskDue = ref('');

const place = computed(() =>
    [lead.value?.locality, lead.value?.region, lead.value?.country]
        .filter(Boolean)
        .join(', '),
);

const emailAllowed = computed(
    () => decisions.value.find((d) => d.channel === 'email')?.allowed === true,
);

/** The one sentence to show when a channel is shut. The guard's own guidance, translated. */
const blockedReason = computed(() => {
    const email = decisions.value.find((d) => d.channel === 'email');

    if (email === undefined || email.allowed) {
        return null;
    }

    const reason = email.reasons[0];

    return reason === undefined ? null : t(reason.guidance);
});

async function load(): Promise<void> {
    if (lead.value === null) {
        state.value = 'loading';
    }

    try {
        const detail = await api.get<
            ApiResource<{
                lead: Lead;
                score: LeadScore | null;
                activities: LeadActivity[];
            }>
        >(`leads/${leadId}`);

        lead.value = detail.data.lead;
        score.value = detail.data.score;
        activities.value = detail.data.activities;
        state.value = 'ready';

        // Both are about *this* team rather than this lead, so a failure on either is not a
        // failure to load the lead — the screen degrades to "no stage picker" rather than to an
        // error page.
        void loadStages();
        void loadDecisions();
    } catch {
        state.value = 'error';
    }
}

async function loadStages(): Promise<void> {
    try {
        const response = await api.get<{ data: PipelineStage[] }>(
            'leads/stages',
        );

        stages.value = response.data;
    } catch {
        stages.value = [];
    }
}

async function loadDecisions(): Promise<void> {
    try {
        const response = await api.get<{ data: OutreachDecision[] }>(
            `leads/${leadId}/outreach/decisions`,
        );

        decisions.value = response.data;
    } catch {
        decisions.value = [];
    }
}

async function moveTo(stage: PipelineStage): Promise<void> {
    if (busy.value || stage.id === lead.value?.stage?.id) {
        return;
    }

    busy.value = true;

    try {
        const response = await api.put<ApiResource<Lead>>(
            `leads/${leadId}/stage`,
            { pipeline_stage_id: stage.id },
        );

        lead.value = response.data;
        await notifySuccess(t('Moved to :stage', { stage: stage.name }));
        // The move writes a timeline entry, so the history below is now out of date.
        void load();
    } catch (error) {
        await notifyFailure(error, t('We could not move that lead.'));
    } finally {
        busy.value = false;
    }
}

async function addNote(): Promise<void> {
    if (busy.value || note.value.trim() === '') {
        return;
    }

    busy.value = true;

    try {
        await api.post(`leads/${leadId}/notes`, { body: note.value.trim() });
        note.value = '';
        await notifySuccess(t('Note added'));
        void load();
    } catch (error) {
        await notifyFailure(error, t('We could not save that note.'));
    } finally {
        busy.value = false;
    }
}

async function addTask(): Promise<void> {
    if (busy.value || taskTitle.value.trim() === '') {
        return;
    }

    busy.value = true;

    try {
        await api.post(`leads/${leadId}/tasks`, {
            title: taskTitle.value.trim(),
            // Omitted rather than sent empty: the server treats a missing `due_at` as "no date",
            // and an empty string is a validation failure.
            ...(taskDue.value === '' ? {} : { due_at: taskDue.value }),
        });

        taskTitle.value = '';
        taskDue.value = '';
        await notifySuccess(t('Task added'));
        void load();
    } catch (error) {
        await notifyFailure(error, t('We could not save that task.'));
    } finally {
        busy.value = false;
    }
}

onMounted(load);

// Coming back from the draft or the log screen, both of which add to the timeline.
onIonViewWillEnter(() => {
    if (lead.value !== null) {
        void load();
    }
});
</script>

<template>
    <IonPage>
        <IonHeader>
            <IonToolbar>
                <IonButtons slot="start">
                    <IonBackButton
                        :text="t('Back')"
                        default-href="/tabs/leads"
                    />
                </IonButtons>
                <IonTitle>{{ lead?.company_name ?? t('Lead') }}</IonTitle>
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

            <div v-else-if="lead" class="alima-page alima-stack">
                <!-- Who. -->
                <header class="lead__head">
                    <div class="alima-row">
                        <GradeBadge :score="lead.score" />
                        <p class="alima-eyebrow">{{ t(lead.source_label) }}</p>
                    </div>
                    <h1 class="alima-h1">{{ lead.company_name }}</h1>
                    <p v-if="place" class="alima-muted">{{ place }}</p>
                    <p v-if="lead.industry" class="alima-muted">
                        {{ lead.industry }}
                    </p>
                    <a
                        v-if="lead.website"
                        :href="lead.website"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="lead__link alima-ltr"
                    >
                        {{ lead.website }}
                    </a>
                </header>

                <!-- A legal limit on how this lead may be approached (ADR 0007). Shown high up,
                     because it changes what every button below means. -->
                <section
                    v-if="lead.outreach_restriction"
                    class="alima-card lead__restriction"
                >
                    <p class="alima-h2">
                        {{ t(lead.outreach_restriction.label) }}
                    </p>
                    <p class="alima-muted">
                        {{ t(lead.outreach_restriction.guidance) }}
                    </p>
                </section>

                <!-- Stage. The ids have to come from the API: a phone cannot know a team's
                     columns. -->
                <section v-if="stages.length > 0" class="alima-card">
                    <div class="alima-section-title">
                        <h2 class="alima-h2">{{ t('Stage') }}</h2>
                    </div>
                    <!-- Real buttons in a labelled group: this is the app's most-used
                         write, and it has to work from a keyboard and from switch control.
                         `aria-pressed` makes the selection audible rather than only a fill,
                         and the tick marks the current column without relying on colour. -->
                    <div
                        class="alima-choices"
                        role="group"
                        :aria-label="t('Stage')"
                    >
                        <button
                            v-for="stage in stages"
                            :key="stage.id"
                            type="button"
                            class="alima-choice"
                            :aria-pressed="stage.id === lead.stage?.id"
                            :aria-current="
                                stage.id === lead.stage?.id ? 'true' : undefined
                            "
                            :disabled="busy"
                            @click="moveTo(stage)"
                        >
                            <IonIcon
                                v-if="stage.id === lead.stage?.id"
                                :icon="checkmarkCircleOutline"
                                aria-hidden="true"
                            />
                            {{ stage.name }}
                        </button>
                    </div>
                </section>

                <!-- Fit. -->
                <section v-if="score" class="alima-card">
                    <!-- The eyebrow sits on its own line rather than beside the
                         heading: the class already uppercases it, and a tracked line that long
                         competes with the `h2` for the same row at phone width. The key keeps
                         its stored capitals, because the web app shares it. -->
                    <div class="alima-section-title">
                        <h2 class="alima-h2">{{ t('Fit') }}</h2>
                    </div>
                    <p class="alima-eyebrow lead__scored">
                        {{ t('SCORED AGAINST YOUR IDEAL CUSTOMER') }}
                    </p>
                    <p class="lead__grade">
                        <span class="alima-mono lead__score">{{
                            score.score
                        }}</span>
                        <span>{{ t(score.grade_label) }}</span>
                    </p>
                    <p v-if="score.explanation" class="lead__explanation">
                        {{ score.explanation }}
                    </p>
                    <ul v-if="score.strengths.length > 0" class="lead__points">
                        <li
                            v-for="(point, index) in score.strengths"
                            :key="index"
                        >
                            {{ point }}
                        </li>
                    </ul>
                    <ul
                        v-if="score.concerns.length > 0"
                        class="lead__points lead__points--concern"
                    >
                        <li
                            v-for="(point, index) in score.concerns"
                            :key="index"
                        >
                            {{ point }}
                        </li>
                    </ul>
                </section>

                <!-- What to do next. -->
                <section class="alima-card">
                    <div class="alima-section-title">
                        <h2 class="alima-h2">{{ t('Outreach') }}</h2>
                    </div>

                    <p v-if="blockedReason" class="lead__blocked">
                        {{ blockedReason }}
                    </p>

                    <div class="lead__actions">
                        <IonButton
                            expand="block"
                            color="secondary"
                            :disabled="!emailAllowed"
                            :router-link="`/leads/${leadId}/draft`"
                            router-direction="forward"
                        >
                            <IonIcon
                                slot="start"
                                :icon="chatbubbleEllipsesOutline"
                                aria-hidden="true"
                            />
                            {{ t('Draft a message') }}
                        </IonButton>

                        <IonButton
                            expand="block"
                            fill="outline"
                            color="primary"
                            :router-link="`/leads/${leadId}/log`"
                            router-direction="forward"
                        >
                            <IonIcon
                                slot="start"
                                :icon="callOutline"
                                aria-hidden="true"
                            />
                            {{ t('Log an approach') }}
                        </IonButton>
                    </div>
                </section>

                <!-- A note, which is most of what a member does from a phone. -->
                <section class="alima-card">
                    <div class="alima-section-title">
                        <h2 class="alima-h2">{{ t('Note') }}</h2>
                    </div>
                    <IonTextarea
                        v-model="note"
                        :auto-grow="true"
                        :rows="2"
                        fill="outline"
                        label-placement="stacked"
                        :label="t('Note (optional)')"
                        :placeholder="t('What did you agree?')"
                        :disabled="busy"
                    />
                    <IonButton
                        expand="block"
                        color="primary"
                        class="lead__submit"
                        :disabled="busy || note.trim() === ''"
                        @click="addNote"
                    >
                        {{ t('Add note') }}
                    </IonButton>
                </section>

                <!-- A task, with a reminder the server computes against its own clock. -->
                <section class="alima-card">
                    <div class="alima-section-title">
                        <h2 class="alima-h2">{{ t('Task') }}</h2>
                    </div>
                    <IonInput
                        v-model="taskTitle"
                        fill="outline"
                        label-placement="stacked"
                        :label="t('What needs doing?')"
                        :disabled="busy"
                    />
                    <IonInput
                        v-model="taskDue"
                        type="date"
                        fill="outline"
                        label-placement="stacked"
                        :label="t('Due')"
                        :disabled="busy"
                        class="lead__date"
                    />
                    <IonButton
                        expand="block"
                        color="primary"
                        class="lead__submit"
                        :disabled="busy || taskTitle.trim() === ''"
                        @click="addTask"
                    >
                        {{ t('Add task') }}
                    </IonButton>
                </section>

                <!-- What has happened. The server sends a type and properties, never a formed
                     sentence, so the wording is composed here and reads in either language. -->
                <section class="alima-card">
                    <div class="alima-section-title">
                        <h2 class="alima-h2">{{ t('Timeline') }}</h2>
                    </div>

                    <p v-if="activities.length === 0" class="alima-muted">
                        {{ t('Nothing has happened yet') }}
                    </p>

                    <ol v-else class="timeline">
                        <li
                            v-for="entry in activities"
                            :key="entry.id"
                            class="timeline__item"
                        >
                            <IonIcon
                                :icon="documentTextOutline"
                                aria-hidden="true"
                                class="timeline__icon"
                            />
                            <div>
                                <p class="timeline__what">
                                    {{ t(entry.type_label) }}
                                </p>
                                <p class="timeline__who">
                                    {{
                                        entry.is_automatic
                                            ? t('Alima')
                                            : (entry.user?.name ??
                                              t('Someone on your team'))
                                    }}
                                    ·
                                    <time :datetime="entry.occurred_at">
                                        {{ formatRelative(entry.occurred_at) }}
                                    </time>
                                </p>
                            </div>
                        </li>
                    </ol>
                </section>
            </div>
        </IonContent>
    </IonPage>
</template>

<style scoped>
.lead__head {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.lead__link {
    color: var(--alima-teal);
    font-size: 0.875rem;
    word-break: break-all;
}

.alima-dark .lead__link {
    color: var(--alima-teal-60);
}

/* A restriction narrows what is allowed; it is not an error. Warning, on a tinted surface so it
   reads as a standing condition rather than as something that just went wrong. */
.lead__restriction {
    border-color: var(--alima-status-warning);
}

.lead__restriction .alima-h2 {
    color: var(--alima-status-warning);
}

.lead__scored {
    margin: 0 0 10px;
}

.lead__grade {
    display: flex;
    align-items: baseline;
    gap: 8px;
    margin: 0 0 8px;
}

.lead__score {
    font-size: 1.75rem;
    font-weight: 600;
    color: var(--alima-text-primary);
}

.lead__explanation {
    margin: 0 0 8px;
    font-size: 0.875rem;
    line-height: 1.6;
    color: var(--alima-text-secondary);
}

.lead__points {
    margin: 0 0 8px;
    padding-inline-start: 1.25rem;
    font-size: 0.875rem;
    line-height: 1.6;
    color: var(--alima-text-secondary);
}

.lead__points--concern {
    color: var(--alima-text-muted);
}

.lead__blocked {
    margin: 0 0 12px;
    font-size: 0.875rem;
    line-height: 1.5;
    color: var(--alima-status-warning);
}

.lead__actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.lead__submit {
    margin-top: 12px;
}

.lead__date {
    margin-top: 10px;
}

.timeline {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.timeline__item {
    display: flex;
    gap: 10px;
    align-items: flex-start;
}

.timeline__icon {
    color: var(--alima-teal);
    font-size: 1rem;
    margin-top: 2px;
    flex: none;
}

.alima-dark .timeline__icon {
    color: var(--alima-teal-60);
}

.timeline__what {
    margin: 0;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--alima-text-primary);
}

.timeline__who {
    margin: 2px 0 0;
    font-size: 0.75rem;
    color: var(--alima-text-muted);
}
</style>
