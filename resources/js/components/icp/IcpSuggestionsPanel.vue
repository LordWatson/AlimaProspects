<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, Sparkles, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/composables/useI18n';
import type { IcpSuggestion } from '@/types';

/**
 * What Alima proposes for the active profile.
 *
 * Every row is a proposal: nothing here has changed the profile, and nothing will until a member
 * says so. Accepting one is an ordinary profile edit; dismissing one is remembered, so the same
 * idea is not put in front of them again.
 */
const props = defineProps<{
    icpId: number;
    icpName: string;
    suggestions: IcpSuggestion[];
    canManage: boolean;
}>();

const { t } = useI18n();

const asking = ref(false);
const deciding = ref<number | null>(null);
// One request at a time across the whole panel: each of these edits or reads the same profile.
const busy = computed(() => asking.value || deciding.value !== null);

function ask(): void {
    router.post(
        `/icp/${props.icpId}/suggestions`,
        {},
        {
            preserveScroll: true,
            onStart: () => (asking.value = true),
            onFinish: () => (asking.value = false),
        },
    );
}

function decide(id: number, verb: 'accept' | 'decline'): void {
    router.post(
        `/icp/suggestions/${id}/${verb}`,
        {},
        {
            preserveScroll: true,
            onStart: () => (deciding.value = id),
            onFinish: () => (deciding.value = null),
        },
    );
}

/** What accepting would do, in a sentence a member can agree or disagree with. */
function headline(suggestion: IcpSuggestion): string {
    const params = {
        type: t(suggestion.type_label),
        value: suggestion.value,
        weight: suggestion.weight ?? '',
    };

    if (suggestion.action === 'remove') {
        return t('Remove :type “:value”', params);
    }

    if (suggestion.action === 'reweight') {
        return t('Change the importance of :type “:value” to :weight', params);
    }

    return suggestion.type === 'exclusion'
        ? t('Rule out “:value”', params)
        : t('Add :type “:value”', params);
}
</script>

<template>
    <section
        class="space-y-5 rounded-2xl border border-border bg-card p-6"
        data-test="suggestions"
    >
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-1">
                <p
                    class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
                >
                    {{ t('Suggestions') }}
                </p>
                <h2
                    class="text-xl font-semibold tracking-tight text-foreground rtl:font-bold"
                >
                    {{ t('Ways to sharpen your active profile') }}
                </h2>
                <p class="max-w-prose text-sm text-muted-foreground" dir="auto">
                    {{
                        t(
                            'Alima looks at your business, how “:name” has graded your leads and what you marked as a good or bad fit, then proposes changes. Nothing changes until you accept it.',
                            { name: icpName },
                        )
                    }}
                </p>
            </div>
            <Button
                v-if="canManage"
                variant="outline"
                :disabled="busy"
                data-test="ask-suggestions"
                @click="ask"
            >
                <Spinner v-if="asking" class="size-4" />
                <Sparkles v-else class="size-4" />
                {{ asking ? t('Thinking…') : t('Suggest improvements') }}
            </Button>
        </div>

        <p
            v-if="!suggestions.length"
            class="text-sm text-muted-foreground"
            data-test="no-suggestions"
        >
            {{
                canManage
                    ? t(
                          'No suggestions waiting. Choose Suggest improvements once a few leads have been graded.',
                      )
                    : t(
                          'No suggestions waiting. An owner or admin can ask for some.',
                      )
            }}
        </p>

        <ul v-else class="divide-y divide-border">
            <li
                v-for="suggestion in suggestions"
                :key="suggestion.id"
                class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between"
                :data-test="`suggestion-${suggestion.id}`"
            >
                <div class="min-w-0 space-y-1">
                    <p
                        class="flex flex-wrap items-center gap-2 text-sm font-medium text-foreground"
                    >
                        <span class="break-words" dir="auto">{{
                            headline(suggestion)
                        }}</span>
                        <Badge
                            v-if="
                                suggestion.action === 'add' &&
                                suggestion.weight !== null
                            "
                            variant="secondary"
                            class="font-mono"
                        >
                            {{
                                t('Importance :weight', {
                                    weight: suggestion.weight,
                                })
                            }}
                        </Badge>
                    </p>
                    <p class="text-sm text-muted-foreground" dir="auto">
                        {{ suggestion.rationale }}
                    </p>
                </div>
                <div v-if="canManage" class="flex shrink-0 gap-2">
                    <Button
                        variant="outline"
                        :disabled="busy"
                        :data-test="`accept-${suggestion.id}`"
                        @click="decide(suggestion.id, 'accept')"
                    >
                        <Check class="size-4" />
                        {{ t('Accept') }}
                    </Button>
                    <Button
                        variant="ghost"
                        :disabled="busy"
                        :data-test="`decline-${suggestion.id}`"
                        @click="decide(suggestion.id, 'decline')"
                    >
                        <X class="size-4" />
                        {{ t('Dismiss') }}
                    </Button>
                </div>
            </li>
        </ul>
    </section>
</template>
