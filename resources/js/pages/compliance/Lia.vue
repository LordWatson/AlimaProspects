<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2, ShieldAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { LiaForm, LiaRecord, Market } from '@/types';

/*
 * The legitimate-interest assessment (Phase 15, docs/compliance.md §2).
 *
 * This is the one screen in the product that is a legal document, and it is written like one rather
 * than dressed up as a wizard. Three questions in the regulator's own order, each with the prompt a
 * person actually needs to answer it — "why are you processing this, and whose interest does it
 * serve" rather than "Purpose".
 *
 * **Saving and signing are two buttons.** A half-written assessment should be savable; a lawful
 * basis should not be affirmable by pressing a button over three empty boxes. Signing is behind a
 * confirmation that says what it means, because it opens lead discovery and carries a name and a
 * date.
 *
 * A **plain header, not a `PageHero`**: this is a sub-page reached from the compliance centre, and
 * brand-tokens.md §7a reads the hero as a sidebar destination's opening card, with sub-pages
 * following `outreach/Suppressions.vue` instead.
 *
 * Brand: cream page, one card. Status tokens only — Signal Orange is decorative and never
 * a warning (brand-tokens.md §2.1). Textareas are `dir="auto"` so an Arabic assessment sets itself
 * right-to-left inside a left-to-right form.
 */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Compliance', href: '/compliance' },
            { title: 'Lawful basis', href: '/compliance/lawful-basis' },
        ],
    },
});

const props = defineProps<{
    assessment: LiaRecord;
    team_market: string;
    options: { markets: { value: Market; label: string }[] };
    review_months: number;
    can: { manage: boolean };
}>();

const { t } = useI18n();
const { date } = useFormat();

const confirming = ref(false);

const form = useForm<LiaForm>({
    market: props.assessment.market,
    purpose: props.assessment.purpose,
    necessity: props.assessment.necessity,
    balancing: props.assessment.balancing,
    safeguards: props.assessment.safeguards ?? '',
    signals_scope: props.assessment.signals_scope ?? '',
    sign: false,
});

/** Switching market loads that market's own assessment — one record per regime. */
function changeMarket(market: string): void {
    router.get(
        '/compliance/lawful-basis',
        { market },
        { preserveState: false },
    );
}

function saveDraft(): void {
    form.sign = false;
    form.put('/compliance/lawful-basis', { preserveScroll: true });
}

function sign(): void {
    form.sign = true;
    form.put('/compliance/lawful-basis', {
        preserveScroll: true,
        onFinish: () => {
            confirming.value = false;
            form.sign = false;
        },
    });
}

function withdraw(): void {
    router.delete('/compliance/lawful-basis', {
        data: { market: form.market },
        preserveScroll: true,
    });
}

const statusVariant = computed(() =>
    props.assessment.in_force
        ? 'success'
        : props.assessment.has_expired
          ? 'warning'
          : 'outline',
);
</script>

<template>
    <Head :title="t('Lawful basis')" />

    <div class="flex flex-col gap-6 py-2">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-2xl">
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Compliance') }}
                </p>
                <h1 class="mt-1 text-2xl font-semibold text-foreground">
                    {{ t('Lawful basis') }}
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{
                        t(
                            'A legitimate-interest assessment is the record that justifies sourcing business contact data. Alima will not run a lead search until one is signed off for your market.',
                        )
                    }}
                </p>
            </div>

            <Button variant="outline" as-child>
                <Link href="/compliance">
                    <ArrowLeft class="size-4 rtl:rotate-180" />
                    {{ t('Compliance') }}
                </Link>
            </Button>
        </header>

        <!-- Where it stands ---------------------------------------------------->
        <section
            class="flex flex-wrap items-start justify-between gap-4 rounded-2xl border border-border bg-card p-6"
        >
            <div class="space-y-2">
                <div class="flex items-center gap-3">
                    <Badge :variant="statusVariant" data-test="lia-status">
                        {{
                            assessment.in_force
                                ? t('In force')
                                : assessment.has_expired
                                  ? t('Due for review')
                                  : t(assessment.status_label)
                        }}
                    </Badge>
                    <span
                        v-if="assessment.id !== null"
                        class="font-mono text-xs text-muted-foreground"
                    >
                        {{ t('Version :n', { n: assessment.version }) }}
                    </span>
                </div>

                <p
                    class="flex items-start gap-2 text-sm"
                    :class="
                        assessment.in_force ? 'text-success' : 'text-warning'
                    "
                >
                    <component
                        :is="assessment.in_force ? CheckCircle2 : ShieldAlert"
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <span>{{ t(assessment.guidance) }}</span>
                </p>

                <p
                    v-if="assessment.completed_at"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        t('Signed off :when.', {
                            when: date(assessment.completed_at),
                        })
                    }}
                    <template v-if="assessment.review_due_at">
                        {{
                            t('Review due :when.', {
                                when: date(assessment.review_due_at),
                            })
                        }}
                    </template>
                </p>
            </div>

            <div class="space-y-2">
                <Label for="market">{{ t('Market') }}</Label>
                <NativeSelect
                    id="market"
                    :model-value="form.market"
                    class="min-w-52"
                    data-test="lia-market"
                    @update:model-value="changeMarket(String($event))"
                >
                    <option
                        v-for="market in options.markets"
                        :key="market.value"
                        :value="market.value"
                    >
                        <!-- One key with a placeholder, not a label plus a translated fragment:
                             a fragment forces English word order on the translator. -->
                        {{
                            market.value === team_market
                                ? t(':market (yours)', {
                                      market: t(market.label),
                                  })
                                : t(market.label)
                        }}
                    </option>
                </NativeSelect>
                <p class="max-w-56 text-xs text-muted-foreground">
                    <!-- Not an administrative detail: the UK runs on legitimate interest and the
                         UAE expects prior consent, so one assessment cannot cover both. -->
                    {{
                        t(
                            'One assessment per market — the rules are not the same in both.',
                        )
                    }}
                </p>
            </div>
        </section>

        <!-- The assessment ---------------------------------------------------->
        <form
            class="space-y-6 rounded-2xl border border-border bg-card p-6"
            @submit.prevent="saveDraft"
        >
            <div class="space-y-2">
                <Label for="purpose">{{ t('1. Purpose') }}</Label>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'What are you using this data for, and whose interest does it serve? Be specific about the service you are offering and who you are offering it to.',
                        )
                    }}
                </p>
                <Textarea
                    id="purpose"
                    v-model="form.purpose"
                    rows="4"
                    dir="auto"
                    :disabled="!can.manage"
                    data-test="lia-purpose"
                />
                <InputError :message="form.errors.purpose" />
            </div>

            <div class="space-y-2">
                <Label for="necessity">{{ t('2. Necessity') }}</Label>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Why is this the way to achieve it? Say what you would have to do instead, and why holding less data would not work.',
                        )
                    }}
                </p>
                <Textarea
                    id="necessity"
                    v-model="form.necessity"
                    rows="4"
                    dir="auto"
                    :disabled="!can.manage"
                    data-test="lia-necessity"
                />
                <InputError :message="form.errors.necessity" />
            </div>

            <div class="space-y-2">
                <Label for="balancing">{{ t('3. Balancing test') }}</Label>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Weigh the prospect’s interests against yours. Would they expect to hear from you? What is the harm if they would rather not, and how easily can they stop it?',
                        )
                    }}
                </p>
                <Textarea
                    id="balancing"
                    v-model="form.balancing"
                    rows="5"
                    dir="auto"
                    :disabled="!can.manage"
                    data-test="lia-balancing"
                />
                <InputError :message="form.errors.balancing" />
            </div>

            <div class="space-y-2">
                <Label for="safeguards">
                    {{ t('Safeguards') }}
                    <span class="text-xs font-normal text-muted-foreground">
                        {{ t('(optional)') }}
                    </span>
                </Label>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'What keeps this proportionate — preferring role-based addresses, honouring opt-outs, your retention windows.',
                        )
                    }}
                </p>
                <Textarea
                    id="safeguards"
                    v-model="form.safeguards"
                    rows="3"
                    dir="auto"
                    :disabled="!can.manage"
                />
                <InputError :message="form.errors.safeguards" />
            </div>

            <div class="space-y-2">
                <Label for="signals_scope">
                    {{ t('Signals') }}
                    <span class="text-xs font-normal text-muted-foreground">
                        {{ t('(only if you run watches)') }}
                    </span>
                </Label>
                <p class="text-sm text-muted-foreground">
                    <!-- Signals processes a named individual's name and public role, which is a
                         different balance from company data (docs/compliance.md §5a). -->
                    {{
                        t(
                            'Signals holds a named person’s name and public role from a published news article. If you use it, assess that separately here.',
                        )
                    }}
                </p>
                <Textarea
                    id="signals_scope"
                    v-model="form.signals_scope"
                    rows="3"
                    dir="auto"
                    :disabled="!can.manage"
                />
                <InputError :message="form.errors.signals_scope" />
            </div>

            <div
                v-if="can.manage"
                class="flex flex-wrap items-center gap-3 border-t border-border pt-5"
            >
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="form.processing"
                    data-test="lia-save"
                >
                    {{ t('Save draft') }}
                </Button>

                <Button
                    type="button"
                    :disabled="form.processing"
                    data-test="lia-sign"
                    @click="confirming = true"
                >
                    {{
                        assessment.in_force
                            ? t('Re-affirm it')
                            : t('Sign it off')
                    }}
                </Button>

                <Button
                    v-if="assessment.id !== null && assessment.in_force"
                    type="button"
                    variant="ghost"
                    class="ms-auto text-destructive"
                    data-test="lia-withdraw"
                    @click="withdraw"
                >
                    {{ t('Withdraw') }}
                </Button>
            </div>
        </form>
    </div>

    <!-- Signing is a statement with a name and a date on it, so it is confirmed. -->
    <Dialog v-model:open="confirming">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ t('Sign off this assessment?') }}</DialogTitle>
                <DialogDescription>
                    {{
                        t(
                            'Your name and today’s date go on the record, and lead discovery opens for this market. Alima will ask you to look at it again in :count months.',
                            { count: review_months },
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="confirming = false">
                    {{ t('Not yet') }}
                </Button>
                <Button
                    :disabled="form.processing"
                    data-test="lia-sign-confirm"
                    @click="sign"
                >
                    {{ t('Sign it off') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
