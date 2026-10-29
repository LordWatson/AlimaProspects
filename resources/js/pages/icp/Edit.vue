<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Ban, Loader2, Plus, Sparkles, X } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { useI18n } from '@/composables/useI18n';
import type { CriterionFormRow, CriterionTypeMeta, Icp } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Ideal customers', href: '/icp' },
            { title: 'Edit profile', href: '/icp' },
        ],
    },
});

const props = defineProps<{
    icp: Icp | null;
    criterionTypes: CriterionTypeMeta[];
}>();

const { t } = useI18n();

const isEditing = computed(() => props.icp !== null);
const signalTypes = computed(() =>
    props.criterionTypes.filter((c) => !c.isExclusion),
);

const fieldClass =
    'border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50';

// Build the initial criteria: an existing ICP's rows, or a short scaffold that guides a new one.
function initialCriteria(): CriterionFormRow[] {
    if (props.icp) {
        return props.icp.criteria.map((c) => ({
            type: c.type,
            value: c.value,
            weight: c.weight,
        }));
    }
    return [
        { type: 'industry', value: '', weight: 70 },
        { type: 'company_size', value: '', weight: 50 },
        { type: 'geography', value: '', weight: 50 },
    ];
}

const form = useForm<{
    name: string;
    description: string;
    is_active: boolean;
    criteria: CriterionFormRow[];
}>({
    name: props.icp?.name ?? '',
    description: props.icp?.description ?? '',
    // A new profile defaults to active (the one Alima will use); an existing one keeps its flag.
    is_active: props.icp?.is_active ?? true,
    criteria: initialCriteria(),
});

// Index-preserving views of the two groups, so error keys (criteria.N.value) still line up.
const signalRows = computed(() =>
    form.criteria
        .map((row, index) => ({ row, index }))
        .filter((r) => r.row.type !== 'exclusion'),
);
const exclusionRows = computed(() =>
    form.criteria
        .map((row, index) => ({ row, index }))
        .filter((r) => r.row.type === 'exclusion'),
);

function placeholderFor(type: string): string {
    return (
        props.criterionTypes.find((c) => c.value === type)?.placeholder ?? ''
    );
}

function addSignal(): void {
    form.criteria.push({
        type: signalTypes.value[0]?.value ?? 'industry',
        value: '',
        weight: 50,
    });
}

function addExclusion(): void {
    form.criteria.push({ type: 'exclusion', value: '', weight: null });
}

function removeAt(index: number): void {
    form.criteria.splice(index, 1);
}

function submit(): void {
    // Drop blank rows so leftover scaffold rows never block a save; this keeps the
    // submitted set identical to what's shown, so server error indices still match.
    form.criteria = form.criteria.filter((r) => r.value.trim() !== '');

    if (isEditing.value && props.icp) {
        form.put(`/icp/${props.icp.id}`);
    } else {
        form.post('/icp');
    }
}
</script>

<template>
    <Head :title="isEditing ? t('Edit profile') : t('New profile')" />

    <form
        class="mx-auto flex max-w-3xl flex-col gap-8 py-2"
        @submit.prevent="submit"
    >
        <header class="space-y-2">
            <p
                class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
            >
                {{ t('Ideal customer profile') }}
            </p>
            <h1
                class="text-3xl font-semibold tracking-tight text-foreground sm:text-4xl rtl:font-bold"
            >
                {{
                    isEditing
                        ? t('Refine your profile')
                        : t('Build your profile')
                }}<span class="text-orange">.</span>
            </h1>
            <p class="text-base text-muted-foreground">
                {{
                    t(
                        'Add the signals that describe a great-fit business, and weight each one by how much it matters. Alima scores every prospect against your active profile.',
                    )
                }}
            </p>
        </header>

        <!-- Profile basics -->
        <Card>
            <CardContent class="space-y-5">
                <div class="grid gap-2">
                    <Label for="name">{{ t('Profile name') }}</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        :placeholder="t('e.g. UK growth agencies')"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="description">{{
                        t('Description (optional)')
                    }}</Label>
                    <textarea
                        id="description"
                        v-model="form.description"
                        rows="2"
                        :class="fieldClass"
                        :placeholder="
                            t(
                                'A one-line reminder of who this profile targets.',
                            )
                        "
                    />
                    <InputError :message="form.errors.description" />
                </div>
                <label
                    class="flex items-center gap-3 rounded-md border border-input px-3 py-2.5 text-sm"
                >
                    <Checkbox v-model="form.is_active" />
                    <span class="flex items-center gap-1.5">
                        <Sparkles class="size-4 text-brand" />
                        {{ t('Use this as my active profile') }}
                    </span>
                </label>
            </CardContent>
        </Card>

        <!-- Signals -->
        <section class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2
                        class="text-lg font-semibold text-foreground rtl:font-bold"
                    >
                        {{ t('Signals') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ t('What a great-fit business looks like.') }}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addSignal"
                >
                    <Plus class="size-4" />
                    {{ t('Add signal') }}
                </Button>
            </div>

            <ul v-if="signalRows.length" class="space-y-3">
                <li
                    v-for="{ row, index } in signalRows"
                    :key="index"
                    class="rounded-xl border border-border bg-card p-4"
                >
                    <div class="flex items-center gap-3">
                        <Select v-model="row.type">
                            <SelectTrigger class="w-40 shrink-0">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="o in signalTypes"
                                    :key="o.value"
                                    :value="o.value"
                                >
                                    {{ t(o.label) }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Input
                            v-model="row.value"
                            class="flex-1"
                            dir="auto"
                            :placeholder="t(placeholderFor(row.type))"
                            :aria-label="t('Signal value')"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="text-muted-foreground hover:text-destructive"
                            :aria-label="t('Remove signal')"
                            @click="removeAt(index)"
                        >
                            <X class="size-4" />
                        </Button>
                    </div>
                    <div class="mt-3 flex items-center gap-3 ps-0.5">
                        <span
                            class="w-20 shrink-0 font-mono text-[0.625rem] tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                        >
                            {{ t('Importance') }}
                        </span>
                        <input
                            v-model.number="row.weight"
                            type="range"
                            min="1"
                            max="100"
                            class="h-1.5 flex-1 cursor-pointer rounded-full accent-brand outline-none focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                            :aria-label="
                                t('Importance for :name', {
                                    name: row.value || t('this signal'),
                                })
                            "
                            :aria-valuetext="`${row.weight} / 100`"
                        />
                        <span
                            class="w-10 shrink-0 text-end font-mono text-sm font-semibold text-brand"
                        >
                            {{ row.weight }}
                        </span>
                    </div>
                    <InputError
                        :message="form.errors[`criteria.${index}.value`]"
                    />
                </li>
            </ul>
            <p
                v-else
                class="rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground"
            >
                {{
                    t(
                        'Add at least one signal so Alima knows what to look for.',
                    )
                }}
            </p>
        </section>

        <!-- Exclusions -->
        <section class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2
                        class="flex items-center gap-2 text-lg font-semibold text-foreground rtl:font-bold"
                    >
                        <Ban class="size-4 text-muted-foreground" />
                        {{ t('Exclusions') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'Businesses to always skip — competitors, existing customers or specific domains.',
                            )
                        }}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addExclusion"
                >
                    <Plus class="size-4" />
                    {{ t('Add exclusion') }}
                </Button>
            </div>

            <ul v-if="exclusionRows.length" class="space-y-2">
                <li
                    v-for="{ row, index } in exclusionRows"
                    :key="index"
                    class="flex items-center gap-3"
                >
                    <Input
                        v-model="row.value"
                        class="flex-1"
                        dir="auto"
                        :placeholder="t(placeholderFor('exclusion'))"
                        :aria-label="t('Exclusion value')"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        class="text-muted-foreground hover:text-destructive"
                        :aria-label="t('Remove exclusion')"
                        @click="removeAt(index)"
                    >
                        <X class="size-4" />
                    </Button>
                </li>
            </ul>
        </section>

        <!-- Footer -->
        <div
            class="flex items-center justify-between gap-3 border-t border-border pt-5"
        >
            <Button variant="ghost" type="button" as-child>
                <Link href="/icp">
                    <ArrowLeft class="size-4 rtl:rotate-180" />
                    {{ t('Cancel') }}
                </Link>
            </Button>
            <Button
                variant="brand"
                type="submit"
                :disabled="form.processing"
                data-test="save-icp"
            >
                <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                {{ isEditing ? t('Save changes') : t('Save profile') }}
            </Button>
        </div>
    </form>
</template>
