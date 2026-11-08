<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2, Lock, ShieldAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    FormActions,
    FormErrorSummary,
    UnsavedChangesDialog,
} from '@/components/forms';
import PageHero from '@/components/PageHero.vue';
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
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
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
 * Four things the rebuild changed, all of them about a long form holding a legal record:
 *
 *  - **A draft cannot be lost by accident any more.** This is the worst thing this screen could do
 *    — a half-written assessment is twenty minutes of somebody's careful wording — and nothing
 *    stopped a mis-clicked breadcrumb. `useUnsavedGuard` holds the navigation and asks. The
 *    **market picker** is held separately, because switching market loads a different record from
 *    the same URL, and a same-path visit is exactly what the guard lets through.
 *  - **The save bar is within reach**, in a sticky footer that also says whether anything is
 *    unsaved — on a form five textareas tall, the buttons were below the fold from the moment it
 *    loaded.
 *  - **What the server rejected is summarised and reachable**, instead of a red line beside
 *    whichever box it belonged to.
 *  - **A member who may not edit it gets the assessment to read, not a dead form.** Any member may
 *    *view* this record by design (`LiaRecordPolicy` — "a member working a list is the one who
 *    will be asked"), and they were being shown five disabled textareas with no explanation. They
 *    now get the text as prose and a line saying who can change it.
 *
 * It opens with the `PageHero` every screen in the product opens with — compact, as a sub-page
 * reached from the compliance centre.
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
const attempt = ref(0);

const form = useForm<LiaForm>({
    market: props.assessment.market,
    purpose: props.assessment.purpose,
    necessity: props.assessment.necessity,
    balancing: props.assessment.balancing,
    safeguards: props.assessment.safeguards ?? '',
    signals_scope: props.assessment.signals_scope ?? '',
    sign: false,
});

const guard = useUnsavedGuard(() => form.isDirty);

/**
 * The market picker, which is a navigation wearing a select.
 *
 * Switching market loads **a different record from the same URL**, and `useUnsavedGuard`
 * deliberately lets a same-path visit through — that is how a list re-requests itself. So the
 * draft has to be defended here instead: a dirty form parks the chosen market and asks first.
 *
 * `selectKey` is bumped when the member backs out, which remounts the select. Without it the
 * control would go on showing the market they cancelled while the form still held the old one.
 */
const pendingMarket = ref<string | null>(null);
const selectKey = ref(0);

function openMarket(market: string): void {
    router.get(
        '/compliance/lawful-basis',
        { market },
        { preserveState: false },
    );
}

function changeMarket(market: string): void {
    if (market === form.market) return;

    if (form.isDirty) {
        pendingMarket.value = market;

        return;
    }

    openMarket(market);
}

/** Either prompt is open: leaving the screen, or switching to another market's record. */
const isPrompting = computed(
    () => guard.isPrompting.value || pendingMarket.value !== null,
);

function discardAndGo(): void {
    const market = pendingMarket.value;

    pendingMarket.value = null;

    if (market !== null) {
        openMarket(market);

        return;
    }

    guard.discard();
}

function stay(): void {
    if (pendingMarket.value !== null) {
        pendingMarket.value = null;
        selectKey.value += 1;

        return;
    }

    guard.stay();
}

function saveDraft(): void {
    attempt.value += 1;
    form.sign = false;
    form.put('/compliance/lawful-basis', { preserveScroll: true });
}

function sign(): void {
    attempt.value += 1;
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

const errorLabels = computed<Record<string, string>>(() => ({
    market: t('Market'),
    purpose: t('1. Purpose'),
    necessity: t('2. Necessity'),
    balancing: t('3. Balancing test'),
    safeguards: t('Safeguards'),
    signals_scope: t('Signals'),
}));

/**
 * The record as prose, for a member who may read it but not change it.
 *
 * Five disabled textareas is not a document: the text is greyed, often clipped to the box, and in
 * some browsers a disabled field cannot even be scrolled to read the rest. The questions are the
 * same ones, in the same order — this is the record, not a summary of it.
 */
const answers = computed(() =>
    [
        { label: t('1. Purpose'), value: props.assessment.purpose },
        { label: t('2. Necessity'), value: props.assessment.necessity },
        { label: t('3. Balancing test'), value: props.assessment.balancing },
        { label: t('Safeguards'), value: props.assessment.safeguards ?? '' },
        { label: t('Signals'), value: props.assessment.signals_scope ?? '' },
    ].map((answer) => ({
        ...answer,
        value: answer.value.trim(),
    })),
);
</script>

<template>
    <Head :title="t('Lawful basis')" />

    <div class="flex flex-col gap-6 py-2">
        <PageHero
            :eyebrow="t('Lawful basis')"
            :title="t('The reason you|may hold this.')"
            :description="
                t(
                    'A legitimate-interest assessment is the record that justifies sourcing business contact data. Alima will not run a lead search until one is signed off for your market.',
                )
            "
            size="compact"
        >
            <template #actions>
                <Button variant="hero" as-child>
                    <Link href="/compliance">
                        <ArrowLeft class="size-4 rtl:rotate-180" />
                        {{ t('Compliance') }}
                    </Link>
                </Button>
            </template>
        </PageHero>

        <FormErrorSummary
            :errors="form.errors"
            :labels="errorLabels"
            :attempt="attempt"
        />

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
                    :key="selectKey"
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

        <!-- The assessment, for somebody who may change it ---------------------->
        <form
            v-if="can.manage"
            class="space-y-6 rounded-2xl border border-border bg-card p-6"
            @submit.prevent="saveDraft"
        >
            <!--
              Each question keeps its own prompt as a full `text-sm` paragraph rather than going
              through `FormField`, whose hint is `text-xs`: here the prompt *is* the question a
              regulator asks, and shrinking it would make the screen a form with small print
              instead of a document with questions. The wiring `FormField` would have supplied is
              written out instead — `aria-describedby` naming the prompt, `aria-invalid` following
              the error.
            -->
            <div class="space-y-2">
                <Label for="purpose">{{ t('1. Purpose') }}</Label>
                <p id="purpose-prompt" class="text-sm text-muted-foreground">
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
                    aria-describedby="purpose-prompt"
                    :aria-invalid="form.errors.purpose ? true : undefined"
                    data-test="lia-purpose"
                />
                <p
                    v-if="form.errors.purpose"
                    id="purpose-error"
                    class="text-sm text-destructive"
                >
                    {{ form.errors.purpose }}
                </p>
            </div>

            <div class="space-y-2">
                <Label for="necessity">{{ t('2. Necessity') }}</Label>
                <p id="necessity-prompt" class="text-sm text-muted-foreground">
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
                    aria-describedby="necessity-prompt"
                    :aria-invalid="form.errors.necessity ? true : undefined"
                    data-test="lia-necessity"
                />
                <p
                    v-if="form.errors.necessity"
                    class="text-sm text-destructive"
                >
                    {{ form.errors.necessity }}
                </p>
            </div>

            <div class="space-y-2">
                <Label for="balancing">{{ t('3. Balancing test') }}</Label>
                <p id="balancing-prompt" class="text-sm text-muted-foreground">
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
                    aria-describedby="balancing-prompt"
                    :aria-invalid="form.errors.balancing ? true : undefined"
                    data-test="lia-balancing"
                />
                <p
                    v-if="form.errors.balancing"
                    class="text-sm text-destructive"
                >
                    {{ form.errors.balancing }}
                </p>
            </div>

            <div class="space-y-2">
                <Label for="safeguards">
                    {{ t('Safeguards') }}
                    <span class="text-xs font-normal text-muted-foreground">
                        {{ t('(optional)') }}
                    </span>
                </Label>
                <p id="safeguards-prompt" class="text-sm text-muted-foreground">
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
                    aria-describedby="safeguards-prompt"
                    :aria-invalid="form.errors.safeguards ? true : undefined"
                />
                <p
                    v-if="form.errors.safeguards"
                    class="text-sm text-destructive"
                >
                    {{ form.errors.safeguards }}
                </p>
            </div>

            <div class="space-y-2">
                <Label for="signals_scope">
                    {{ t('Signals') }}
                    <span class="text-xs font-normal text-muted-foreground">
                        {{ t('(only if you run watches)') }}
                    </span>
                </Label>
                <p
                    id="signals-scope-prompt"
                    class="text-sm text-muted-foreground"
                >
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
                    aria-describedby="signals-scope-prompt"
                    :aria-invalid="form.errors.signals_scope ? true : undefined"
                />
                <p
                    v-if="form.errors.signals_scope"
                    class="text-sm text-destructive"
                >
                    {{ form.errors.signals_scope }}
                </p>
            </div>

            <FormActions
                :dirty="form.isDirty"
                :processing="form.processing"
                :bleed="false"
            >
                <Button
                    v-if="assessment.id !== null && assessment.in_force"
                    type="button"
                    variant="ghost"
                    class="text-destructive"
                    data-test="lia-withdraw"
                    @click="withdraw"
                >
                    {{ t('Withdraw') }}
                </Button>
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="form.processing"
                    data-test="lia-save"
                >
                    {{ form.processing ? t('Saving…') : t('Save draft') }}
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
            </FormActions>
        </form>

        <!-- The assessment, for somebody who may only read it ------------------->
        <section
            v-else
            class="space-y-6 rounded-2xl border border-border bg-card p-6"
            data-test="lia-readonly"
        >
            <!-- Who can change it, said plainly. The alternative — and what this screen used to
                 do — is five disabled boxes and no explanation. This is information, not a
                 warning, so it takes the neutral surface rather than a status tint. -->
            <div
                class="flex items-start gap-3 rounded-xl border border-border bg-background/60 p-4"
                role="note"
            >
                <Lock
                    class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <div class="space-y-0.5">
                    <p
                        class="text-sm font-semibold text-foreground rtl:font-bold"
                    >
                        {{ t('Only an owner or an admin can change this') }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'It is here for you to read: this is the answer to “why are we allowed to hold this person’s details”, and you are the one who will be asked.',
                            )
                        }}
                    </p>
                </div>
            </div>

            <div
                v-for="answer in answers"
                :key="answer.label"
                class="space-y-1"
            >
                <h2 class="text-sm font-semibold text-foreground rtl:font-bold">
                    {{ answer.label }}
                </h2>
                <p
                    v-if="answer.value"
                    class="text-sm leading-relaxed whitespace-pre-line text-foreground"
                    dir="auto"
                >
                    {{ answer.value }}
                </p>
                <p v-else class="text-sm text-muted-foreground">
                    {{ t('Not written yet.') }}
                </p>
            </div>
        </section>
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

    <!-- Leaving, or switching to another market's record, with a draft unsaved. -->
    <UnsavedChangesDialog
        :open="isPrompting"
        @stay="stay"
        @discard="discardAndGo"
    />
</template>
