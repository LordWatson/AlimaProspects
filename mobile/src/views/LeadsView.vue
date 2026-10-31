<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import {
    IonContent,
    IonHeader,
    IonInfiniteScroll,
    IonInfiniteScrollContent,
    IonList,
    IonPage,
    IonRefresher,
    IonRefresherContent,
    IonSearchbar,
    IonSegment,
    IonSegmentButton,
    IonTitle,
    IonToolbar,
    onIonViewWillEnter,
} from '@ionic/vue';
import type { ApiPage } from '@shared/api';
import type { Lead, LeadSort, ScoreGrade } from '@shared/types';
import { api } from '@/lib/api';
import { t } from '@/lib/i18n';
import LeadListItem from '@/components/LeadListItem.vue';
import StateMessage from '@/components/StateMessage.vue';

/*
 * The leads list: `GET /leads`, paged.
 *
 * Three controls, which is what fits under a thumb: a search box, a grade filter and a sort.
 * The web app's filter bar has eleven — sources, enrichment status, country, stages, tags,
 * assignees, saved views — and reproducing it here would produce a filter screen rather than a
 * list of leads. The two kept are the two that change what a member does next: whether a company
 * is worth a call, and which companies they are looking for.
 *
 * Paged rather than loaded whole, with `per_page` well under the API's ceiling of 100: the API
 * caps it precisely so a client cannot ask for the table in one query (`docs/api/README.md` §5),
 * and 20 is a screen and a half on a phone.
 */
const leads = ref<Lead[]>([]);
const state = ref<'loading' | 'ready' | 'error'>('loading');
const page = ref(1);
const lastPage = ref(1);

const search = ref('');
const grade = ref<ScoreGrade | 'all'>('all');
const sort = ref<LeadSort>('newest');

const perPage = 20;

const hasMore = computed(() => page.value < lastPage.value);

const grades: (ScoreGrade | 'all')[] = ['all', 'A', 'B', 'C', 'D'];

const sorts: { value: LeadSort; label: string }[] = [
    { value: 'newest', label: 'Newest first' },
    { value: 'fit', label: 'Best fit' },
    { value: 'company', label: 'Company name' },
];

function query(targetPage: number): Record<string, string | number | string[]> {
    const params: Record<string, string | number | string[]> = {
        page: targetPage,
        per_page: perPage,
        sort: sort.value,
    };

    if (search.value.trim() !== '') {
        params.search = search.value.trim();
    }

    // Repeated as `grades[]`, which is what Laravel reads — the shared client does that for an
    // array value, so the screen does not have to know.
    if (grade.value !== 'all') {
        params.grades = [grade.value];
    }

    return params;
}

async function load(): Promise<void> {
    if (leads.value.length === 0) {
        state.value = 'loading';
    }

    try {
        const response = await api.get<ApiPage<Lead>>('leads', query(1));

        leads.value = response.data;
        page.value = response.meta.current_page;
        lastPage.value = response.meta.last_page;
        state.value = 'ready';
    } catch {
        state.value = 'error';
    }
}

/** The next page, appended. Resolves when the scroller may ask again. */
async function loadMore(event: CustomEvent): Promise<void> {
    const target = event.target as HTMLIonInfiniteScrollElement | null;

    if (!hasMore.value) {
        await target?.complete();

        return;
    }

    try {
        const response = await api.get<ApiPage<Lead>>(
            'leads',
            query(page.value + 1),
        );

        // Guard against a lead arriving twice: a row created between two page requests shifts
        // everything after it, and the same id can land on two pages.
        const seen = new Set(leads.value.map((lead) => lead.id));

        leads.value = [
            ...leads.value,
            ...response.data.filter((lead) => !seen.has(lead.id)),
        ];
        page.value = response.meta.current_page;
        lastPage.value = response.meta.last_page;
    } catch {
        // Stop asking. The rows already on screen stay, and a pull-to-refresh starts over.
        lastPage.value = page.value;
    } finally {
        await target?.complete();
    }
}

async function refresh(event: CustomEvent): Promise<void> {
    await load();
    (event.target as HTMLIonRefresherElement | null)?.complete();
}

async function changeGrade(next: ScoreGrade | 'all'): Promise<void> {
    grade.value = next;
    await load();
}

onMounted(load);

// Coming back from a lead whose stage or tags may have changed.
onIonViewWillEnter(() => {
    if (leads.value.length > 0) {
        void load();
    }
});
</script>

<template>
    <IonPage>
        <IonHeader>
            <IonToolbar>
                <IonTitle>{{ t('Leads') }}</IonTitle>
            </IonToolbar>
            <IonToolbar>
                <IonSearchbar
                    v-model="search"
                    :placeholder="t('Company, number, website or contact')"
                    :debounce="400"
                    enterkeyhint="search"
                    @ion-input="load"
                    @ion-clear="load"
                />
            </IonToolbar>
        </IonHeader>

        <IonContent :fullscreen="true">
            <IonRefresher slot="fixed" @ion-refresh="refresh">
                <IonRefresherContent />
            </IonRefresher>

            <div class="leads__controls">
                <IonSegment
                    :value="grade"
                    scrollable
                    @ion-change="
                        changeGrade($event.detail.value as ScoreGrade | 'all')
                    "
                >
                    <IonSegmentButton
                        v-for="option in grades"
                        :key="option"
                        :value="option"
                    >
                        {{ option === 'all' ? t('All') : option }}
                    </IonSegmentButton>
                </IonSegment>

                <div class="leads__sort">
                    <label class="alima-eyebrow" for="lead-sort">{{
                        t('Sort')
                    }}</label>
                    <select
                        id="lead-sort"
                        v-model="sort"
                        class="alima-select leads__select"
                        @change="load"
                    >
                        <option
                            v-for="option in sorts"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ t(option.label) }}
                        </option>
                    </select>
                </div>
            </div>

            <StateMessage v-if="state === 'loading'" state="loading" />

            <StateMessage
                v-else-if="state === 'error'"
                state="error"
                :message="t('We could not load your leads.')"
                :hint="
                    t(
                        'Alima could not be reached. Check your connection and try again.',
                    )
                "
                @retry="load"
            />

            <StateMessage
                v-else-if="leads.length === 0"
                state="empty"
                :message="
                    search !== '' || grade !== 'all'
                        ? t('No leads match these filters')
                        : t('No leads yet')
                "
                :hint="
                    search !== '' || grade !== 'all'
                        ? t('Try widening the search or clearing the grade.')
                        : t(
                              'Run your first search on the Alima website, and the results will be here.',
                          )
                "
            />

            <template v-else>
                <IonList lines="full">
                    <LeadListItem
                        v-for="lead in leads"
                        :key="lead.id"
                        :lead="lead"
                    />
                </IonList>

                <IonInfiniteScroll
                    :disabled="!hasMore"
                    @ion-infinite="loadMore"
                >
                    <IonInfiniteScrollContent
                        :loading-text="t('Loading')"
                        loading-spinner="crescent"
                    />
                </IonInfiniteScroll>
            </template>
        </IonContent>
    </IonPage>
</template>

<style scoped>
.leads__controls {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 12px calc(16px + var(--ion-safe-area-left, 0px)) 4px
        calc(16px + var(--ion-safe-area-right, 0px));
}

.leads__sort {
    display: flex;
    align-items: center;
    gap: 8px;
}

/* A native `<select>` rather than `ion-select`: the OS picker is the control a member already
   knows, it needs no modal, and it is reachable with a keyboard and with switch control. Its
   size and touch target come from `.alima-select`, which is 16px for a reason — see there. */
.leads__select {
    flex: 1;
}
</style>
