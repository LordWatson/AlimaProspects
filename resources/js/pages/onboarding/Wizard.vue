<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Check, Loader2, Pencil } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHero from '@/components/PageHero.vue';
import ListInput from '@/components/onboarding/ListInput.vue';
import WizardProgress from '@/components/onboarding/WizardProgress.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type {
    BusinessProfileForm,
    OnboardingOptions,
    OnboardingProgressProp,
    OnboardingStepMeta,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Business profile', href: '/onboarding' }],
    },
});

const props = defineProps<{
    steps: OnboardingStepMeta[];
    progress: OnboardingProgressProp;
    profile: BusinessProfileForm;
    options: OnboardingOptions;
    can: { update: boolean };
}>();

const { t } = useI18n();
const { currency: formatCurrency } = useFormat();

// Shared input styling for the native textarea / number inputs (matches the Input component).
const fieldClass =
    'border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50';

const stepFields: Record<string, (keyof BusinessProfileForm)[]> = {
    company: ['company_name', 'website'],
    industry: ['industry', 'company_size'],
    locations: ['locations', 'regions_served', 'primary_market'],
    offering: ['products_services', 'value_proposition'],
    pricing: [
        'pricing_currency',
        'pricing_min',
        'pricing_max',
        'customer_examples',
    ],
};

const form = useForm({
    company_name: props.profile.company_name ?? '',
    website: props.profile.website ?? '',
    industry: props.profile.industry ?? '',
    company_size: props.profile.company_size ?? '',
    locations: [...props.profile.locations],
    regions_served: [...props.profile.regions_served],
    primary_market: props.profile.primary_market ?? '',
    products_services: [...props.profile.products_services],
    value_proposition: props.profile.value_proposition ?? '',
    pricing_currency: props.profile.pricing_currency ?? '',
    pricing_min: props.profile.pricing_min,
    pricing_max: props.profile.pricing_max,
    customer_examples: [...props.profile.customer_examples],
});

const initialIndex = Math.max(
    0,
    props.steps.findIndex((s) => s.key === props.progress.currentStep),
);
const currentIndex = ref(initialIndex);

const currentStep = computed(() => props.steps[currentIndex.value]);
// Completed steps come from the server and refresh after each save.
const completedKeys = computed(() => props.progress.completedSteps);
const isLastDataStep = computed(() => currentStep.value?.key === 'pricing');

// --- option label lookups (for the review summary) -------------------------------------------
function label(
    list: { value: string; label: string }[],
    value: string | null,
): string {
    return list.find((o) => o.value === value)?.label ?? '—';
}

function toggleRegion(value: string, checked: boolean | 'indeterminate'): void {
    const set = new Set(form.regions_served);
    if (checked === true) {
        set.add(value);
    } else {
        set.delete(value);
    }
    form.regions_served = [...set];
}

function coerceMoney(value: unknown): number | null {
    if (value === '' || value === null || value === undefined) {
        return null;
    }
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
}

function goTo(index: number): void {
    if (index >= 0 && index < props.steps.length) {
        currentIndex.value = index;
    }
}

function back(): void {
    goTo(currentIndex.value - 1);
}

function saveAndNext(): void {
    const step = currentStep.value;
    if (!step) {
        return;
    }

    if (!step.isData) {
        finish();
        return;
    }

    const fields = stepFields[step.key] ?? [];

    form.transform((data) => {
        const record = data as Record<string, unknown>;
        const payload: Record<string, unknown> = {};
        for (const field of fields) {
            payload[field] = record[field];
        }
        if (step.key === 'pricing') {
            payload.pricing_min = coerceMoney(payload.pricing_min);
            payload.pricing_max = coerceMoney(payload.pricing_max);
        }
        return payload;
    }).post(`/onboarding/step/${step.key}`, {
        preserveScroll: true,
        onSuccess: () => goTo(currentIndex.value + 1),
    });
}

function finish(): void {
    router.post('/onboarding/complete', {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('Set up your business')" />

    <div class="flex flex-col gap-8 py-2">
        <PageHero
            index="02"
            :eyebrow="t('Business profile')"
            :title="
                progress.isComplete
                    ? 'Who you are,|in your words.'
                    : 'Tell us who you|are.'
            "
            :description="
                t(
                    'Tell us who you are and who you serve, so Alima can find and qualify the right prospects for you. You can stop and pick up where you left off at any time.',
                )
            "
            :meta="
                t('Step :current of :total', {
                    current: currentIndex + 1,
                    total: steps.length,
                })
            "
        />

        <div class="grid max-w-5xl gap-8 lg:grid-cols-[13rem_1fr]">
            <WizardProgress
                :steps="steps"
                :current-index="currentIndex"
                :completed-keys="completedKeys"
                @go="goTo"
            />

            <Card class="overflow-hidden">
                <CardContent class="space-y-6">
                    <div
                        v-if="!can.update"
                        class="rounded-md bg-muted px-4 py-3 text-sm text-muted-foreground"
                    >
                        {{
                            t(
                                'You can view the setup, but only an owner or admin can change it.',
                            )
                        }}
                    </div>

                    <header class="space-y-1">
                        <h2
                            class="text-xl font-semibold text-foreground rtl:font-bold"
                        >
                            {{ t(currentStep?.label ?? '') }}
                        </h2>
                    </header>

                    <!-- Step: Company -->
                    <fieldset
                        v-if="currentStep?.key === 'company'"
                        :disabled="!can.update"
                        class="space-y-5"
                    >
                        <div class="grid gap-2">
                            <Label for="company_name">{{
                                t('Company name')
                            }}</Label>
                            <Input
                                id="company_name"
                                v-model="form.company_name"
                                autocomplete="organization"
                                :placeholder="t('e.g. Alima Marketing Ltd')"
                            />
                            <InputError :message="form.errors.company_name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="website">{{ t('Website') }}</Label>
                            <Input
                                id="website"
                                v-model="form.website"
                                type="url"
                                inputmode="url"
                                placeholder="https://"
                            />
                            <InputError :message="form.errors.website" />
                            <p class="text-xs text-muted-foreground">
                                {{
                                    t(
                                        'Optional — but it helps Alima learn about you.',
                                    )
                                }}
                            </p>
                        </div>
                    </fieldset>

                    <!-- Step: Industry & size -->
                    <fieldset
                        v-else-if="currentStep?.key === 'industry'"
                        :disabled="!can.update"
                        class="space-y-5"
                    >
                        <div class="grid gap-2">
                            <Label for="industry">{{ t('Industry') }}</Label>
                            <Input
                                id="industry"
                                v-model="form.industry"
                                :placeholder="t('e.g. Marketing & advertising')"
                            />
                            <InputError :message="form.errors.industry" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="company_size">{{
                                t('Company size')
                            }}</Label>
                            <Select v-model="form.company_size">
                                <SelectTrigger id="company_size" class="w-full">
                                    <SelectValue
                                        :placeholder="t('Choose a size')"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="o in options.companySizes"
                                        :key="o.value"
                                        :value="o.value"
                                    >
                                        {{ t(o.label) }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.company_size" />
                        </div>
                    </fieldset>

                    <!-- Step: Locations & regions -->
                    <fieldset
                        v-else-if="currentStep?.key === 'locations'"
                        :disabled="!can.update"
                        class="space-y-6"
                    >
                        <div class="grid gap-2">
                            <Label for="locations">{{
                                t('Where are you based?')
                            }}</Label>
                            <ListInput
                                v-model="form.locations"
                                input-id="locations"
                                :list-label="t('Locations')"
                                :placeholder="
                                    t('Add a city or country, then press Enter')
                                "
                            />
                            <InputError :message="form.errors.locations" />
                        </div>
                        <fieldset class="grid gap-2">
                            <legend class="mb-2 text-sm">
                                {{ t('Which regions do you serve?') }}
                            </legend>
                            <div class="grid gap-2 sm:grid-cols-3">
                                <label
                                    v-for="o in options.regions"
                                    :key="o.value"
                                    class="flex items-center gap-2.5 rounded-md border border-input px-3 py-2.5 text-sm"
                                >
                                    <Checkbox
                                        :model-value="
                                            form.regions_served.includes(
                                                o.value,
                                            )
                                        "
                                        @update:model-value="
                                            (c) => toggleRegion(o.value, c)
                                        "
                                    />
                                    {{ t(o.label) }}
                                </label>
                            </div>
                            <InputError :message="form.errors.regions_served" />
                        </fieldset>
                        <div class="grid gap-2">
                            <Label for="primary_market">{{
                                t('Primary market')
                            }}</Label>
                            <Select v-model="form.primary_market">
                                <SelectTrigger
                                    id="primary_market"
                                    class="w-full"
                                >
                                    <SelectValue
                                        :placeholder="
                                            t('Choose your main market')
                                        "
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="o in options.markets"
                                        :key="o.value"
                                        :value="o.value"
                                    >
                                        {{ t(o.label) }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    t(
                                        'This sets your regional and compliance defaults.',
                                    )
                                }}
                            </p>
                            <InputError :message="form.errors.primary_market" />
                        </div>
                    </fieldset>

                    <!-- Step: Offering -->
                    <fieldset
                        v-else-if="currentStep?.key === 'offering'"
                        :disabled="!can.update"
                        class="space-y-6"
                    >
                        <div class="grid gap-2">
                            <Label for="products_services">{{
                                t('Products & services')
                            }}</Label>
                            <ListInput
                                v-model="form.products_services"
                                input-id="products_services"
                                :list-label="t('Products and services')"
                                :placeholder="t('Add one, then press Enter')"
                            />
                            <InputError
                                :message="form.errors.products_services"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="value_proposition">{{
                                t('Value proposition')
                            }}</Label>
                            <textarea
                                id="value_proposition"
                                v-model="form.value_proposition"
                                rows="4"
                                :class="fieldClass"
                                :placeholder="
                                    t(
                                        'In a sentence or two, what do you help customers achieve?',
                                    )
                                "
                            />
                            <InputError
                                :message="form.errors.value_proposition"
                            />
                        </div>
                    </fieldset>

                    <!-- Step: Pricing & customers -->
                    <fieldset
                        v-else-if="currentStep?.key === 'pricing'"
                        :disabled="!can.update"
                        class="space-y-6"
                    >
                        <div class="grid gap-2">
                            <Label for="pricing_currency">{{
                                t('Currency')
                            }}</Label>
                            <Select v-model="form.pricing_currency">
                                <SelectTrigger
                                    id="pricing_currency"
                                    class="w-full"
                                >
                                    <SelectValue
                                        :placeholder="t('Choose a currency')"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="o in options.currencies"
                                        :key="o.value"
                                        :value="o.value"
                                    >
                                        {{ t(o.label) }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError
                                :message="form.errors.pricing_currency"
                            />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="pricing_min">{{
                                    t('Typical deal size (from)')
                                }}</Label>
                                <input
                                    id="pricing_min"
                                    v-model="form.pricing_min"
                                    type="number"
                                    min="0"
                                    inputmode="numeric"
                                    :class="fieldClass"
                                    placeholder="0"
                                />
                                <InputError
                                    :message="form.errors.pricing_min"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="pricing_max">{{
                                    t('Typical deal size (to)')
                                }}</Label>
                                <input
                                    id="pricing_max"
                                    v-model="form.pricing_max"
                                    type="number"
                                    min="0"
                                    inputmode="numeric"
                                    :class="fieldClass"
                                    placeholder="0"
                                />
                                <InputError
                                    :message="form.errors.pricing_max"
                                />
                            </div>
                        </div>
                        <div class="grid gap-2">
                            <Label for="customer_examples">{{
                                t('Example customers')
                            }}</Label>
                            <ListInput
                                v-model="form.customer_examples"
                                input-id="customer_examples"
                                :list-label="t('Example customers')"
                                :placeholder="
                                    t('Add a customer name, then press Enter')
                                "
                            />
                            <p class="text-xs text-muted-foreground">
                                {{
                                    t(
                                        'Optional — a few names help Alima find lookalikes.',
                                    )
                                }}
                            </p>
                            <InputError
                                :message="form.errors.customer_examples"
                            />
                        </div>
                    </fieldset>

                    <!-- Step: Review -->
                    <div
                        v-else-if="currentStep?.key === 'review'"
                        class="space-y-5"
                    >
                        <p class="text-sm text-muted-foreground">
                            {{
                                t(
                                    'Here’s what Alima will work from. Edit anything before you finish.',
                                )
                            }}
                        </p>
                        <dl
                            class="divide-y divide-border rounded-lg border border-border"
                        >
                            <div
                                v-for="row in [
                                    {
                                        step: 0,
                                        term: t('Company name'),
                                        value: form.company_name || '—',
                                    },
                                    {
                                        step: 0,
                                        term: t('Website'),
                                        value: form.website || '—',
                                    },
                                    {
                                        step: 1,
                                        term: t('Industry'),
                                        value: form.industry || '—',
                                    },
                                    {
                                        step: 1,
                                        term: t('Company size'),
                                        value: t(
                                            label(
                                                options.companySizes,
                                                form.company_size,
                                            ),
                                        ),
                                    },
                                    {
                                        step: 2,
                                        term: t('Locations'),
                                        value: form.locations.join(', ') || '—',
                                    },
                                    {
                                        step: 2,
                                        term: t('Regions served'),
                                        value:
                                            form.regions_served
                                                .map((r) =>
                                                    t(
                                                        label(
                                                            options.regions,
                                                            r,
                                                        ),
                                                    ),
                                                )
                                                .join(', ') || '—',
                                    },
                                    {
                                        step: 2,
                                        term: t('Primary market'),
                                        value: t(
                                            label(
                                                options.markets,
                                                form.primary_market,
                                            ),
                                        ),
                                    },
                                    {
                                        step: 3,
                                        term: t('Products & services'),
                                        value:
                                            form.products_services.join(', ') ||
                                            '—',
                                    },
                                    {
                                        step: 3,
                                        term: t('Value proposition'),
                                        value: form.value_proposition || '—',
                                    },
                                    {
                                        step: 4,
                                        term: t('Typical deal size'),
                                        value:
                                            form.pricing_min != null ||
                                            form.pricing_max != null
                                                ? `${formatCurrency(Number(form.pricing_min ?? 0) * 100, (form.pricing_currency || 'gbp').toUpperCase())} – ${formatCurrency(Number(form.pricing_max ?? 0) * 100, (form.pricing_currency || 'gbp').toUpperCase())}`
                                                : '—',
                                    },
                                    {
                                        step: 4,
                                        term: t('Example customers'),
                                        value:
                                            form.customer_examples.join(', ') ||
                                            '—',
                                    },
                                ]"
                                :key="row.term"
                                class="flex items-start justify-between gap-4 px-4 py-3"
                            >
                                <div class="min-w-0">
                                    <dt class="text-xs text-muted-foreground">
                                        {{ row.term }}
                                    </dt>
                                    <dd
                                        class="mt-0.5 text-sm break-words text-foreground"
                                        dir="auto"
                                    >
                                        {{ row.value }}
                                    </dd>
                                </div>
                                <Button
                                    v-if="can.update"
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="t('Edit')"
                                    @click="goTo(row.step)"
                                >
                                    <Pencil class="size-4" />
                                </Button>
                            </div>
                        </dl>
                    </div>

                    <!-- Footer -->
                    <div
                        class="flex items-center justify-between gap-3 border-t border-border pt-5"
                    >
                        <Button
                            v-if="currentIndex > 0"
                            variant="ghost"
                            type="button"
                            :disabled="form.processing"
                            @click="back"
                        >
                            <ArrowLeft class="size-4" />
                            {{ t('Back') }}
                        </Button>
                        <span v-else />

                        <Button
                            v-if="can.update"
                            variant="brand"
                            type="button"
                            :disabled="form.processing"
                            :data-test="
                                currentStep?.key === 'review'
                                    ? 'finish-onboarding'
                                    : 'continue-onboarding'
                            "
                            @click="saveAndNext"
                        >
                            <Loader2
                                v-if="form.processing"
                                class="size-4 animate-spin"
                            />
                            <template v-if="currentStep?.key === 'review'">
                                <Check class="size-4" />
                                {{ t('Finish setup') }}
                            </template>
                            <template v-else>
                                {{
                                    isLastDataStep ? t('Review') : t('Continue')
                                }}
                                <ArrowRight class="size-4" />
                            </template>
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
