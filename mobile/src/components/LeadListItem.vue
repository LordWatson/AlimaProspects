<script setup lang="ts">
import { computed } from 'vue';
import { IonItem, IonLabel, IonNote } from '@ionic/vue';
import type { Lead } from '@shared/types';
import GradeBadge from '@/components/GradeBadge.vue';
import { t } from '@/lib/i18n';

/*
 * One row of the leads list.
 *
 * The three facts a member scans for on a phone, and nothing else: who it is, where it is, and
 * how well it fits. Everything else — contacts, tags, tasks, the timeline — is on the detail
 * screen, one tap away. A list row that tries to carry all of it is a list nobody can read at
 * arm's length.
 *
 * `outreach_restriction` earns its place in the row because it changes what the member is allowed
 * to do next, and finding that out only after drafting a message is the wrong order (ADR 0007).
 */
const props = defineProps<{ lead: Lead }>();

const place = computed(() =>
    [props.lead.locality, props.lead.country].filter(Boolean).join(', '),
);
</script>

<template>
    <IonItem
        button
        :detail="true"
        :router-link="`/leads/${lead.id}`"
        router-direction="forward"
        lines="full"
    >
        <!-- A plain element carries Ionic's `slot`, not the component: `slot` on a Vue component
             is a Vue directive, and Ionic needs the attribute on a real DOM node. -->
        <div slot="start" class="row__grade">
            <GradeBadge :score="lead.score" />
        </div>

        <IonLabel>
            <h2 class="row__name">{{ lead.company_name }}</h2>
            <p class="row__meta">
                <span v-if="place">{{ place }}</span>
                <span v-if="place && lead.industry" aria-hidden="true">·</span>
                <span v-if="lead.industry">{{ lead.industry }}</span>
            </p>
            <p v-if="lead.outreach_restriction" class="row__flag">
                {{ t(lead.outreach_restriction.label) }}
            </p>
        </IonLabel>

        <IonNote slot="end" class="row__stage">
            {{ lead.stage?.name ?? t(lead.status_label) }}
        </IonNote>
    </IonItem>
</template>

<style scoped>
.row__grade {
    margin-inline-end: 12px;
}

.row__name {
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--alima-text-primary);
}

.row__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    font-size: 0.8125rem;
    color: var(--alima-text-muted);
}

/* A legal limit on how this lead may be approached. Warning rather than error: it narrows what
   is allowed, it does not mean something has gone wrong. */
.row__flag {
    font-size: 0.75rem;
    color: var(--alima-status-warning);
}

.row__stage {
    font-size: 0.75rem;
    max-width: 8rem;
    text-align: end;
    white-space: normal;
}
</style>
