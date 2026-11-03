<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Lightbulb } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useI18n } from '@/composables/useI18n';
import type { SignalTuningSuggestion } from '@/types';

/*
 * What Alima thinks you should change, with the evidence (S12).
 *
 * **The evidence is the component, not the recommendation.** A suggestion a member cannot check is
 * one they can only obey or ignore, and neither builds trust in a feature whose whole job is to
 * decide what reaches their feed. So every card leads with the counts — "you dismissed 11 of the 12
 * stories this outlet brought you" — and the proposal is the second line. The numbers are the
 * argument; the button is just where it ends up.
 *
 * **Nothing here has already happened.** The advisor writes proposals and a member accepts or
 * declines, which is a product decision rather than a UI one: a feature that silently retunes
 * itself cannot be debugged by the person supporting it. Declining is as prominent as accepting,
 * and it carries a promise the product keeps — the same suggestion is never raised again.
 *
 * Brand (§2a): the panel is a plain `bg-card` surface with **no accent of its own**. A tuning
 * suggestion is not a status and not an alert — Signal Orange would be wrong twice over, and a
 * `status.*` token would be reporting a condition that does not exist. A muted icon and muted
 * supporting text are the whole decoration, and neither button is the default variant, because the
 * screen this sits on already has its one primary in the hero.
 */
const props = defineProps<{
    suggestions: SignalTuningSuggestion[];
    /** False for a read-only member: they see the reasoning, not the buttons. */
    canManage: boolean;
}>();

const { t } = useI18n();

/** The row being answered, so two clicks cannot send two requests. */
const answering = ref<number | null>(null);

function answer(suggestion: SignalTuningSuggestion, accept: boolean): void {
    if (answering.value !== null) return;

    answering.value = suggestion.id;

    router.post(
        `/signals/tuning/${suggestion.id}/${accept ? 'accept' : 'decline'}`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                answering.value = null;
            },
        },
    );
}

/**
 * The counts behind a proposal, as "label: value" pairs.
 *
 * Built from whatever the advisor recorded rather than from a fixed list, because the three kinds
 * of proposal count different things — and a panel that only knew how to render one kind's evidence
 * would quietly show nothing for the others.
 */
function evidenceRows(
    suggestion: SignalTuningSuggestion,
): { key: string; label: string; value: string }[] {
    /*
     * **Keys of this panel's own, not the short generic ones.** `Kept` and `Source` already exist
     * in the translation files with other meanings — `Kept` was written for S9's preview verdict
     * and reads "will appear" in Arabic, and `Source` means "Discovery" — so reusing them would
     * have made the Arabic evidence panel say something close to nonsense. On the one panel whose
     * entire job is to be checkable. (Found by `brand-reviewer`.)
     *
     * The lesson generalises: a one-word key is almost always already taken, and a reused key is a
     * translation bug that nothing in the English build can see.
     */
    const labels: Record<string, string> = {
        kept: 'Stories you kept',
        dismissed: 'Stories you dismissed',
        dismissals: 'Stories you dismissed',
        observations: 'Times seen',
        rate: 'Share of them',
        source_name: 'Outlet',
        definition_name: 'Signal type',
    };

    return Object.entries(suggestion.evidence)
        .filter(([key, value]) => key in labels && value !== null)
        .map(([key, value]) => ({
            // Keyed on the **evidence key**, not the label: `dismissed` and `dismissals` share a
            // label, and keying a `v-for` on it would give Vue two identical keys.
            key,
            label: labels[key],
            value:
                key === 'rate'
                    ? `${Math.round(Number(value) * 100)}%`
                    : String(value),
        }));
}
</script>

<template>
    <Card v-if="props.suggestions.length" as="section">
        <CardContent class="flex flex-col gap-4">
            <!-- `flex-nowrap`: wrapping drops the icon onto its own line above the heading on a
                 narrow screen, which reads as a stray bullet. -->
            <div class="flex flex-nowrap items-start gap-2">
                <Lightbulb
                    class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <div class="space-y-1">
                    <h2
                        class="text-lg font-semibold text-foreground rtl:font-bold"
                    >
                        {{ t('Ways to sharpen your feed') }}
                    </h2>
                    <!-- The promise that makes the panel safe to read: nothing in it has
                         happened yet, and nothing will without a click. -->
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'Built from what you have kept and dismissed. Nothing changes until you say so.',
                            )
                        }}
                    </p>
                </div>
            </div>

            <ul class="space-y-4">
                <li
                    v-for="suggestion in props.suggestions"
                    :key="suggestion.id"
                    class="space-y-2 border-t border-border pt-4"
                    :data-test="`tuning-${suggestion.id}`"
                >
                    <!-- The evidence first, deliberately. A member should be able to disagree
                         with the numbers before they read what Alima concluded from them. -->
                    <dl
                        class="flex flex-wrap gap-x-4 gap-y-1 font-mono text-xs text-muted-foreground tabular-nums"
                    >
                        <div
                            v-for="row in evidenceRows(suggestion)"
                            :key="row.key"
                            class="flex gap-1"
                        >
                            <dt>{{ t(row.label) }}:</dt>
                            <dd class="text-foreground" dir="auto">
                                {{ row.value }}
                            </dd>
                        </div>
                    </dl>

                    <p
                        class="text-sm font-semibold text-foreground rtl:font-bold"
                    >
                        {{ t(suggestion.kind_label) }}
                        <span
                            v-if="suggestion.definition"
                            class="font-normal text-muted-foreground rtl:font-normal"
                            dir="auto"
                        >
                            —
                            {{
                                suggestion.definition.name_translatable
                                    ? t(suggestion.definition.name)
                                    : suggestion.definition.name
                            }}
                            <template v-if="suggestion.definition.deleted">
                                ({{ t('removed') }})
                            </template>
                        </span>
                    </p>

                    <p class="text-sm text-muted-foreground">
                        {{ t(suggestion.kind_explanation) }}
                    </p>

                    <div v-if="props.canManage" class="flex flex-wrap gap-2">
                        <!-- Declining is as easy to reach as accepting, and it keeps a promise:
                             the advisor never raises the same thing twice.

                             Both carry the proposal's own name in their accessible name. A screen
                             reader hearing "No thanks, No thanks, Do it, Do it" down a list of
                             three has been told nothing (WCAG 2.4.6).

                             And **neither is the default variant**: three Ink buttons down a panel,
                             under a screen that already has a hero primary, is three primaries on
                             one view (brand-tokens.md §2.3). `outline` is the precedent the card's
                             own "Convert anyway" already set. -->
                        <Button
                            variant="ghost"
                            size="sm"
                            :aria-label="`${t('No thanks')}: ${t(suggestion.kind_label)}`"
                            :aria-disabled="
                                answering !== null ? 'true' : undefined
                            "
                            :data-test="`decline-${suggestion.id}`"
                            @click="answer(suggestion, false)"
                        >
                            {{ t('No thanks') }}
                        </Button>
                        <!-- `aria-disabled`, not `disabled`: a disabled button drops focus to
                             `<body>` the moment it is pressed (brand-tokens.md §7j.3), and the
                             guard that actually prevents a double submit is in the handler. -->
                        <Button
                            variant="outline"
                            size="sm"
                            :aria-label="`${t('Do it')}: ${t(suggestion.kind_label)}`"
                            :aria-disabled="
                                answering !== null ? 'true' : undefined
                            "
                            :data-test="`accept-${suggestion.id}`"
                            @click="answer(suggestion, true)"
                        >
                            {{ t('Do it') }}
                        </Button>
                    </div>
                </li>
            </ul>
        </CardContent>
    </Card>
</template>
