<script setup lang="ts">
import {
    IonBackButton,
    IonBadge,
    IonButtons,
    IonContent,
    IonHeader,
    IonItem,
    IonLabel,
    IonList,
    IonNote,
    IonPage,
    IonRefresher,
    IonRefresherContent,
    IonSegment,
    IonSegmentButton,
    IonTitle,
    IonToolbar,
    onIonViewWillEnter,
} from '@ionic/vue';
import { onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import type { ApiPage } from '@shared/api';
import type { SignalPlaybookRun } from '@shared/types';
import { api } from '@/lib/api';
import { formatRelative } from '@/lib/format';
import { t } from '@/lib/i18n';
import StateMessage from '@/components/StateMessage.vue';

/*
 * The playbook inbox: what fired, and what needs a person (S14, plan decision #5).
 *
 * **Reading only.** Building rules stays on the web, which is the Phase 18 decision rather than an
 * omission: a rule builder is a long form with conditions, an ordered list of steps and a preview,
 * and none of that is work somebody does between appointments. What *is* field work is the other
 * half — a rule wrote an approach overnight and somebody has to read it before it goes anywhere, or
 * a rule was refused and somebody should know.
 *
 * So the refusals lead. The segment defaults to "needs you", because a list of successful
 * automation is a list nobody opens twice, and the rows that matter are the ones where Alima
 * declined to do something — above all a draft the outreach guard refused, which is a compliance
 * answer and not a glitch.
 *
 * Every label here is a **translation key** with params beside it, composed by `t()` at render
 * time, for the same reason the bell's are: the row may have been written days ago, by a queue
 * worker with no locale, and the member may since have switched the app to Arabic.
 *
 * Types come from `@shared/types` by alias, never by copy (`docs/modules/mobile-app.md`).
 */
const router = useRouter();

const items = ref<SignalPlaybookRun[]>([]);
const state = ref<'loading' | 'ready' | 'error'>('loading');
const scope = ref<'attention' | 'all'>('attention');

async function load(): Promise<void> {
    if (items.value.length === 0) {
        state.value = 'loading';
    }

    try {
        const response = await api.get<ApiPage<SignalPlaybookRun>>(
            'signal-playbooks/runs',
            scope.value === 'attention'
                ? { per_page: 30, attention: 1 }
                : { per_page: 30 },
        );

        items.value = response.data;
        state.value = 'ready';
    } catch {
        state.value = 'error';
    }
}

/** Open the lead the rule acted on — the only onward journey this screen has. */
async function open(run: SignalPlaybookRun): Promise<void> {
    if (run.lead_id !== null) {
        await router.push(`/leads/${run.lead_id}`);
    }
}

async function refresh(event: CustomEvent): Promise<void> {
    await load();
    (event.target as HTMLIonRefresherElement | null)?.complete();
}

watch(scope, () => {
    // The two scopes are different queries, so the list is cleared rather than filtered on the
    // phone: filtering what one page happened to hold would under-report the other scope.
    items.value = [];
    void load();
});

onMounted(load);

onIonViewWillEnter(() => {
    if (items.value.length > 0) {
        void load();
    }
});
</script>

<template>
    <IonPage>
        <IonHeader>
            <IonToolbar>
                <IonButtons slot="start">
                    <IonBackButton default-href="/tabs/dashboard" />
                </IonButtons>
                <IonTitle>{{ t('What your rules did') }}</IonTitle>
            </IonToolbar>
            <IonToolbar>
                <IonSegment v-model="scope">
                    <IonSegmentButton value="attention">
                        <!-- `ion-text-wrap`: `IonLabel` truncates with an ellipsis otherwise, and
                         the lines it would cut are the refusal reasons — the one thing somebody
                         opened this screen to read. -->
                        <IonLabel class="ion-text-wrap">{{
                            t('Needs you')
                        }}</IonLabel>
                    </IonSegmentButton>
                    <IonSegmentButton value="all">
                        <IonLabel>{{ t('Everything') }}</IonLabel>
                    </IonSegmentButton>
                </IonSegment>
            </IonToolbar>
        </IonHeader>

        <IonContent :fullscreen="true">
            <IonRefresher slot="fixed" @ion-refresh="refresh">
                <IonRefresherContent />
            </IonRefresher>

            <StateMessage v-if="state === 'loading'" state="loading" />

            <StateMessage
                v-else-if="state === 'error'"
                state="error"
                :message="t('We could not load what your rules have done.')"
                @retry="load"
            />

            <StateMessage
                v-else-if="items.length === 0"
                state="empty"
                :message="
                    scope === 'attention'
                        ? t('Nothing waiting on you')
                        : t('Nothing has happened yet')
                "
                :hint="
                    scope === 'attention'
                        ? t('Every step your rules took went through.')
                        : t(
                              'Rules are set up on the web. Nothing has fired yet.',
                          )
                "
            />

            <IonList v-else lines="full">
                <IonItem
                    v-for="run in items"
                    :key="run.id"
                    :button="run.lead_id !== null"
                    :detail="run.lead_id !== null"
                    @click="open(run)"
                >
                    <IonLabel>
                        <h2>{{ run.playbook_name }}</h2>
                        <p>{{ run.signal_headline }}</p>
                        <!-- Every step, with what became of it. A phone has room for this because
                             a rule carries a handful of steps, and the refusal is the whole reason
                             somebody opened the screen. -->
                        <p
                            v-for="(step, index) in run.steps"
                            :key="`${run.id}-${index}`"
                        >
                            {{ t(step.kind_label) }} —
                            {{ t(step.status_label) }}
                            <template v-if="step.reason_label">
                                · {{ t(step.reason_label) }}
                            </template>
                        </p>
                    </IonLabel>

                    <IonNote slot="end">
                        <!-- `warning`, not `danger`: a refusal is the product doing its job, and
                             the status ramp's red is for things that went wrong (CLAUDE.md §2a).
                             The word is in the badge, so the colour is not carrying the meaning
                             on its own. -->
                        <IonBadge v-if="run.needs_attention" color="warning">
                            {{ t('Worth a look') }}
                        </IonBadge>
                        <!-- **Not `v-else-if`**: a rehearsal that also refused something showed
                             only "Worth a look", so a hypothetical refusal read as a real one. -->
                        <IonBadge v-if="run.is_rehearsal" color="medium">
                            {{ t('Rehearsal') }}
                        </IonBadge>
                        <span v-if="run.created_at">
                            {{ formatRelative(run.created_at) }}
                        </span>
                    </IonNote>
                </IonItem>
            </IonList>
        </IonContent>
    </IonPage>
</template>
