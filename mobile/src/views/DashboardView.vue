<script setup lang="ts">
import { onMounted, ref } from 'vue';
import {
    IonContent,
    IonHeader,
    IonPage,
    IonRefresher,
    IonRefresherContent,
    IonSegment,
    IonSegmentButton,
    IonTitle,
    IonToolbar,
    onIonViewWillEnter,
} from '@ionic/vue';
import type { ApiResource } from '@shared/api';
import type { DashboardReport, ReportRange } from '@shared/types';
import { api } from '@/lib/api';
import { t } from '@/lib/i18n';
import { currentTeam, currentUser } from '@/lib/session';
import { formatDate } from '@/lib/format';
import FunnelList from '@/components/FunnelList.vue';
import KpiTile from '@/components/KpiTile.vue';
import StateMessage from '@/components/StateMessage.vue';

/*
 * The home screen: the same figures the web dashboard shows, for the same window.
 *
 * One request — `GET /reports/dashboard` returns the whole read-model, built by
 * `DashboardReportBuilder`, so the phone does not assemble a dashboard from six endpoints.
 *
 * What is **not** here is as deliberate as what is. The web dashboard has eleven tables, a
 * trend chart and a CSV export; this has the KPI row, the funnel and the grade mix. A phone
 * screen that tried to carry the rest would carry none of it legibly, and the three things here
 * are the ones that answer "how are we doing" at a glance.
 *
 * `has_data` and `has_ever_sourced` are different questions and are treated as such: a team with
 * leads that filters to a quiet week has an empty **window**, not an empty account, and must not
 * be told it has never started.
 */
const report = ref<DashboardReport | null>(null);
const state = ref<'loading' | 'ready' | 'error'>('loading');
const range = ref<ReportRange>('last_30_days');

/** The windows worth offering on a phone — the full set lives on the web dashboard. */
const ranges: ReportRange[] = ['last_7_days', 'last_30_days', 'last_90_days'];

const rangeLabels: Record<string, string> = {
    last_7_days: 'Last 7 days',
    last_30_days: 'Last 30 days',
    last_90_days: 'Last 90 days',
};

async function load(): Promise<void> {
    if (report.value === null) {
        state.value = 'loading';
    }

    try {
        const response = await api.get<ApiResource<DashboardReport>>(
            'reports/dashboard',
            { range: range.value },
        );

        report.value = response.data;
        state.value = 'ready';
    } catch {
        // The sentence is on the screen, with a retry. No toast: a screen that cannot load is
        // already telling the member something went wrong, and a toast on top of it is noise.
        state.value = 'error';
    }
}

async function changeRange(next: ReportRange): Promise<void> {
    range.value = next;
    await load();
}

async function refresh(event: CustomEvent): Promise<void> {
    await load();
    (event.target as HTMLIonRefresherElement | null)?.complete();
}

onMounted(load);

// Coming back from a lead the member just moved: the figures behind them have changed.
onIonViewWillEnter(() => {
    if (report.value !== null) {
        void load();
    }
});
</script>

<template>
    <IonPage>
        <IonHeader>
            <IonToolbar>
                <IonTitle>{{ t('Dashboard') }}</IonTitle>
            </IonToolbar>
        </IonHeader>

        <IonContent :fullscreen="true">
            <IonRefresher slot="fixed" @ion-refresh="refresh">
                <IonRefresherContent />
            </IonRefresher>

            <div class="alima-page alima-stack">
                <header>
                    <p class="alima-eyebrow">
                        {{ currentTeam?.name ?? t('Your team') }}
                    </p>
                    <h1 class="alima-h1">
                        {{
                            currentUser
                                ? t('Hello, :name', {
                                      name: currentUser.name.split(' ')[0],
                                  })
                                : t('Dashboard')
                        }}
                    </h1>
                </header>

                <IonSegment
                    :value="range"
                    @ion-change="
                        changeRange($event.detail.value as ReportRange)
                    "
                >
                    <IonSegmentButton
                        v-for="option in ranges"
                        :key="option"
                        :value="option"
                    >
                        {{ t(rangeLabels[option] ?? option) }}
                    </IonSegmentButton>
                </IonSegment>

                <StateMessage v-if="state === 'loading'" state="loading" />

                <StateMessage
                    v-else-if="state === 'error'"
                    state="error"
                    :message="t('We could not load your dashboard.')"
                    :hint="
                        t(
                            'Alima could not be reached. Check your connection and try again.',
                        )
                    "
                    @retry="load"
                />

                <template v-else-if="report">
                    <!-- A team that has never sourced anything needs a first step, not a grid
                         of zeros. -->
                    <StateMessage
                        v-if="!report.has_ever_sourced"
                        state="empty"
                        :message="t('Nothing sourced yet.')"
                        :hint="
                            t(
                                'Run your first search on the Alima website, and the results will be here.',
                            )
                        "
                    />

                    <template v-else>
                        <p v-if="report.range_from" class="dash__window">
                            {{
                                t(':from to :to', {
                                    from: formatDate(
                                        report.range_from,
                                        currentTeam?.timezone ?? null,
                                    ),
                                    to: formatDate(
                                        report.range_to,
                                        currentTeam?.timezone ?? null,
                                    ),
                                })
                            }}
                        </p>

                        <div class="alima-grid-2">
                            <KpiTile
                                v-for="kpi in report.kpis"
                                :key="kpi.key"
                                :kpi="kpi"
                            />
                        </div>

                        <section class="alima-card">
                            <div class="alima-section-title">
                                <h2 class="alima-h2">{{ t('Funnel') }}</h2>
                            </div>
                            <FunnelList :steps="report.funnel" />
                        </section>

                        <section class="alima-card">
                            <div class="alima-section-title">
                                <h2 class="alima-h2">{{ t('Fit mix') }}</h2>
                            </div>
                            <ul class="grades">
                                <li
                                    v-for="slice in report.grades"
                                    :key="slice.key"
                                    class="grades__row"
                                >
                                    <span class="grades__label">{{
                                        t(slice.label)
                                    }}</span>
                                    <span class="grades__value alima-mono">{{
                                        slice.value
                                    }}</span>
                                </li>
                            </ul>
                        </section>

                        <p
                            v-if="!report.has_data"
                            class="alima-muted dash__quiet"
                        >
                            {{ t('Nothing in this period') }}
                        </p>
                    </template>
                </template>
            </div>
        </IonContent>
    </IonPage>
</template>

<style scoped>
.dash__window {
    margin: 0;
    font-size: 0.8125rem;
    color: var(--alima-text-muted);
}

.dash__quiet {
    text-align: center;
}

.grades {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.grades__row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
}

.grades__label {
    font-size: 0.9375rem;
    color: var(--alima-text-primary);
}

.grades__value {
    font-size: 0.9375rem;
    font-weight: 500;
    color: var(--alima-text-primary);
}
</style>
