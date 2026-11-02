<script setup lang="ts">
import { IonButton, IonSpinner } from '@ionic/vue';
import { t } from '@/lib/i18n';

/*
 * The three states that are not "here is your data", in one component so they read the same on
 * every screen: still loading, nothing to show, or it went wrong.
 *
 * `empty` and `error` are kept apart deliberately. "You have no leads yet" and "we could not
 * load your leads" look identical if you only check whether the array is empty, and telling
 * someone they have no customers when the request failed is the worse of the two mistakes.
 */
defineProps<{
    state: 'loading' | 'empty' | 'error';
    /** Shown on `empty` and `error`. A finished sentence — translate before passing it. */
    message?: string;
    /** A second line, for what to do next. */
    hint?: string;
}>();

const emit = defineEmits<{ retry: [] }>();
</script>

<template>
    <div class="state" role="status" aria-live="polite">
        <template v-if="state === 'loading'">
            <IonSpinner name="crescent" color="secondary" />
            <p class="state__message">{{ t('Loading') }}</p>
        </template>

        <template v-else>
            <p class="state__message">
                {{ message ?? t('Nothing here yet') }}
            </p>
            <p v-if="hint" class="state__hint">{{ hint }}</p>
            <IonButton
                v-if="state === 'error'"
                fill="outline"
                color="secondary"
                size="small"
                class="state__retry"
                @click="emit('retry')"
            >
                {{ t('Try again') }}
            </IonButton>
        </template>
    </div>
</template>

<style scoped>
.state {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 48px 24px;
    text-align: center;
}

.state__message {
    margin: 0;
    font-weight: 500;
    color: var(--alima-text-primary);
}

.state__hint {
    margin: 0;
    font-size: 0.875rem;
    line-height: 1.5;
    color: var(--alima-text-muted);
    max-width: 32ch;
}

.state__retry {
    margin-top: 8px;
}
</style>
