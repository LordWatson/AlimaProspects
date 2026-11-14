<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Lightbulb } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/composables/useI18n';
import type {
    SignalDefinition,
    SignalDraft,
    SignalSuggestion,
    SignalSuggestionBatch,
} from '@/types';

/**
 * Signal types Alima suggests for this business (Signals v3 P2).
 *
 * Every row is a suggestion and nothing here has been added. A library type is added as it ships;
 * a drafted one opens in the same review form a composed draft does, where the member can change
 * it and run their own preview before saving.
 *
 * The work is queued, so while a batch is being drafted or its sample searches are out this asks
 * the server for the `suggestions` prop again every few seconds, and stops when nothing is pending.
 */
const props = defineProps<{
    batch: SignalSuggestionBatch | null;
    /** The library types this team has not taken, to add one by key. */
    presets: SignalDefinition[];
    /** The team is at its ceiling of signal types, so nothing more can be added. */
    atCeiling: boolean;
    /** Preset keys with an add request in flight. */
    adding: string[];
    /** Names of the types the team already has, so a draft they saved is not offered again. */
    ownedNames: string[];
}>();

const emit = defineEmits<{
    useDraft: [draft: SignalDraft];
    addPreset: [preset: SignalDefinition];
}>();

const { t } = useI18n();

const asking = ref(false);
const working = computed(() => props.batch?.working === true);
// The server ranks by what the sample searches found. While they are still coming back the rows
// stay in the order they were drafted, so one does not move under a pointer as another finishes.
const suggestions = computed(() => {
    const rows = props.batch?.suggestions ?? [];

    return working.value ? [...rows].sort((a, b) => a.index - b.index) : rows;
});

function owned(suggestion: SignalSuggestion): boolean {
    const name = suggestion.draft?.definition?.name?.trim().toLowerCase();

    return (
        name !== undefined &&
        props.ownedNames.some((n) => n.trim().toLowerCase() === name)
    );
}
// A new request is offered when there is nothing to show: no batch, or one that failed.
const canAsk = computed(() => props.batch === null || props.batch.failed);

function ask(): void {
    router.post(
        '/signals/types/suggestions',
        {},
        {
            preserveScroll: true,
            onStart: () => (asking.value = true),
            onFinish: () => (asking.value = false),
        },
    );
}

let timer: ReturnType<typeof setInterval> | null = null;

function stop(): void {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}

watch(
    working,
    (now) => {
        stop();

        if (now) {
            timer = setInterval(
                () => router.reload({ only: ['suggestions', 'flash'] }),
                4000,
            );
        }
    },
    { immediate: true },
);

onBeforeUnmount(stop);

function presetFor(suggestion: SignalSuggestion): SignalDefinition | null {
    return (
        props.presets.find((preset) => preset.key === suggestion.preset_key) ??
        null
    );
}

/** What the sample search found, in a sentence. */
function finding(suggestion: SignalSuggestion): string {
    if (suggestion.state === 'testing') {
        return t('Checking what this finds in this week’s news…');
    }

    if (suggestion.state === 'untested' || suggestion.preview === null) {
        return t(
            'Not tested. Open it and run a preview before you rely on it.',
        );
    }

    if (suggestion.preview.kept > 0) {
        return t(
            'A sample search found events that would reach your feed: :kept, from results: :results.',
            {
                kept: suggestion.preview.kept,
                results: suggestion.preview.results,
            },
        );
    }

    return t(
        'A sample search found nothing this week. You can still add it, but it is unproven.',
    );
}
</script>

<template>
    <section
        class="space-y-4 rounded-[1.25rem] border border-transparent bg-card p-6 shadow-sm dark:border-border"
        aria-labelledby="suggestions-heading"
        data-test="signal-suggestions"
    >
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-start gap-3">
                <span
                    class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-lilac-40 text-ink"
                >
                    <Lightbulb class="size-4" aria-hidden="true" />
                </span>
                <div class="min-w-0 space-y-1">
                    <h2
                        id="suggestions-heading"
                        class="text-lg font-semibold text-foreground rtl:font-bold"
                    >
                        {{ t('Signals for your business') }}
                    </h2>
                    <p class="max-w-prose text-base text-muted-foreground">
                        {{
                            t(
                                'Alima reads your business profile and ideal customer, suggests the events worth watching for, and tests each against this week’s news. Nothing is added until you add it.',
                            )
                        }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'Asking uses your AI allowance and some of today’s previews. You can ask once a day.',
                            )
                        }}
                    </p>
                </div>
            </div>
            <Button
                v-if="canAsk"
                variant="outline"
                :disabled="asking"
                :aria-busy="asking ? true : undefined"
                data-test="ask-signal-suggestions"
                @click="ask"
            >
                <Spinner v-if="asking" class="size-4" />
                {{ batch?.failed ? t('Try again') : t('Suggest signal types') }}
            </Button>
        </div>

        <!-- One live region for the three things that change without the member doing anything. -->
        <div aria-live="polite">
            <p
                v-if="batch?.failed"
                class="text-base text-foreground"
                data-test="suggestions-failed"
            >
                {{
                    t(
                        'Alima could not suggest anything just now. Nothing was added.',
                    )
                }}
            </p>

            <p
                v-else-if="batch && working && !suggestions.length"
                class="flex items-center gap-2 text-base text-muted-foreground"
                data-test="suggestions-pending"
            >
                <Spinner class="size-4" />
                {{
                    t(
                        'Reading your business profile and drafting. This takes about a minute.',
                    )
                }}
            </p>

            <p
                v-else-if="batch && !suggestions.length"
                class="text-base text-muted-foreground"
                data-test="suggestions-empty"
            >
                {{
                    t(
                        'Nothing to suggest yet. Alima needs to know more about what you sell and who buys it. Add to your business profile, then ask again tomorrow.',
                    )
                }}
            </p>
        </div>

        <ul v-if="suggestions.length" class="divide-y divide-border">
            <li
                v-for="suggestion in suggestions"
                :key="suggestion.index"
                class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between"
                :data-test="`signal-suggestion-${suggestion.index}`"
            >
                <div class="min-w-0 space-y-1">
                    <p
                        class="flex flex-wrap items-center gap-2 text-base font-medium text-foreground"
                    >
                        <span class="break-words" dir="auto">{{
                            suggestion.title
                        }}</span>
                        <Badge variant="secondary">
                            {{
                                suggestion.preset_key
                                    ? t('From the library')
                                    : t('Drafted for you')
                            }}
                        </Badge>
                    </p>
                    <p class="text-base text-muted-foreground" dir="auto">
                        {{ suggestion.rationale }}
                    </p>
                    <p
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                        :data-test="`suggestion-finding-${suggestion.index}`"
                    >
                        <Spinner
                            v-if="suggestion.state === 'testing'"
                            class="size-3.5"
                        />
                        {{ finding(suggestion) }}
                    </p>
                </div>
                <div class="shrink-0">
                    <template v-if="suggestion.preset_key">
                        <Button
                            v-if="presetFor(suggestion)"
                            variant="outline"
                            :disabled="
                                atCeiling ||
                                adding.includes(suggestion.preset_key)
                            "
                            :aria-label="
                                t('Add “:title” to my types', {
                                    title: suggestion.title,
                                })
                            "
                            :data-test="`add-suggestion-${suggestion.index}`"
                            @click="emit('addPreset', presetFor(suggestion)!)"
                        >
                            {{ t('Add to my types') }}
                        </Button>
                        <span v-else class="text-sm text-muted-foreground">
                            {{ t('Already in your types') }}
                        </span>
                    </template>
                    <span
                        v-else-if="owned(suggestion)"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('Already in your types') }}
                    </span>
                    <Button
                        v-else-if="suggestion.draft"
                        variant="outline"
                        :disabled="atCeiling"
                        :aria-label="
                            t('Review and add “:title”', {
                                title: suggestion.title,
                            })
                        "
                        :data-test="`review-suggestion-${suggestion.index}`"
                        @click="emit('useDraft', suggestion.draft)"
                    >
                        {{ t('Review and add') }}
                    </Button>
                </div>
            </li>
        </ul>

        <p
            v-if="atCeiling && suggestions.length"
            class="text-sm text-muted-foreground"
            data-test="suggestions-ceiling"
        >
            {{
                t(
                    'You have as many signal types as your plan allows. Remove one to add another.',
                )
            }}
        </p>
    </section>
</template>
