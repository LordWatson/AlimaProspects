<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Plus, ShieldCheck, Upload, X } from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import {
    FormActions,
    FormErrorSummary,
    FormField,
    FormSection,
    UnsavedChangesDialog,
} from '@/components/forms';
import PageHero from '@/components/PageHero.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { CreateLeadForm, LeadTag, PipelineStage } from '@/types';

/*
 * Add one lead by hand — the company a member met at an event, was referred to, or found somewhere
 * Alima doesn't look.
 *
 * The shape of the screen is the point: **only the company name is required**, and it is the first
 * thing on the page. Someone typing this on a phone while they still remember the conversation
 * should be able to fill one field and be done; everything below it is there for when they happen
 * to know it, and enrichment fills in the rest afterwards. A form that insisted on a website and a
 * registry number would be a form people give up on, and the lead would be lost.
 *
 * Four things the rebuild added, all of them things a four-section form cannot do without:
 *
 *  - **A summary of what the server rejected**, at the top, announced, with each failure a link
 *    into its field. A rejected email address used to show as a red line in the third section,
 *    off-screen, with nothing at the top of the page to say so.
 *  - **`aria-invalid` and `aria-describedby` on every field**, via `FormField`. Not one of the
 *    forty-odd hand-wired fields in this product had the first of those, so a field the server
 *    had rejected looked wrong and read fine.
 *  - **The save button within reach**, in a sticky bar that also says whether anything is unsaved.
 *  - **A guard on leaving.** This is the screen somebody fills in from a half-remembered
 *    conversation; losing it to a mis-clicked breadcrumb was the worst thing it could do.
 *
 * The contact note is not a warning the member can click past: it is what they are agreeing to by
 * typing someone's details in (docs/compliance.md). It uses `warning`, never Signal Orange, which
 * is decorative only (brand-tokens.md §2.1).
 *
 * The page takes the **full width** it is given, unlike its sibling `Import.vue` — that screen is
 * one file picker and a list of mappings, and reads better in a column, while this one is four
 * sections of fields that would otherwise leave half the screen empty. The fields go four up at
 * `lg`, so the space becomes columns rather than page-wide inputs; the ones where a long line is
 * actually harder to use — the company name, the note, the stage picker, the tag box — keep a cap
 * of their own.
 */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Leads', href: '/leads' },
            { title: 'Add a lead', href: '/leads/create' },
        ],
    },
});

const props = defineProps<{
    stages: PipelineStage[];
    /** Tags the team already uses, offered so a third spelling of "warm" doesn't get invented. */
    tags: LeadTag[];
    /** `code` is ISO-3166-1 alpha-2; `name` is a translation key. */
    countries: { code: string; name: string }[];
    can: { import: boolean };
}>();

const { t } = useI18n();

const form = useForm<CreateLeadForm>({
    company_name: '',
    company_number: '',
    website: '',
    industry: '',
    employee_range: '',
    locality: '',
    region: '',
    postal_code: '',
    country: '',
    contact_name: '',
    contact_title: '',
    contact_email: '',
    contact_phone: '',
    tags: [],
    pipeline_stage_id:
        props.stages.find((stage) => stage.is_default)?.id ?? null,
    note: '',
});

// Hold back a navigation that would throw the draft away. The save itself is a POST and goes
// through untouched; only leaving is held.
const guard = useUnsavedGuard(() => form.isDirty);

/**
 * What each failure is called in the summary, and where the summary sends you.
 *
 * The labels are the field labels, so the summary and the form agree word for word. Only the two
 * fields whose control id doesn't follow from the key need an anchor of their own.
 */
const errorLabels = computed<Record<string, string>>(() => ({
    company_name: t('Company name'),
    website: t('Website'),
    company_number: t('Company number'),
    industry: t('Industry'),
    employee_range: t('Team size'),
    locality: t('Town or city'),
    region: t('Region'),
    postal_code: t('Postcode'),
    country: t('Country'),
    contact_name: t('Their name'),
    contact_title: t('Their role'),
    contact_email: t('Email'),
    contact_phone: t('Phone'),
    tags: t('Tags'),
    pipeline_stage_id: t('Pipeline stage'),
    note: t('Note'),
}));

const errorAnchors: Record<string, string> = {
    pipeline_stage_id: 'stage',
    tags: 'new-tag',
};

// --- Tags ----------------------------------------------------------------------------------
// Names rather than ids, matching the lead's work panel: a tag is created on first use, so the
// screen never has to create one and then attach it.
const newTag = ref('');
const tagInput = useTemplateRef<HTMLElement>('tagInput');

/**
 * What a screen reader is told when a chip appears or disappears. Chips are added and removed
 * without a round trip, so nothing else on the page would say anything at all.
 */
const tagAnnouncement = ref('');

function addTag(name: string): void {
    const tag = name.trim();

    if (tag === '') return;

    if (
        !form.tags.some(
            (existing) => existing.toLowerCase() === tag.toLowerCase(),
        )
    ) {
        form.tags = [...form.tags, tag];
        tagAnnouncement.value = t('Tagged :name', { name: tag });
    }

    newTag.value = '';
}

async function removeTag(name: string): Promise<void> {
    form.tags = form.tags.filter((tag) => tag !== name);
    tagAnnouncement.value = t('Untagged :name', { name });

    // The button that was clicked has just unmounted, so focus would otherwise land on the body.
    // It goes to the field the member would reach for next.
    await nextTick();
    tagInput.value?.querySelector('input')?.focus();
}

/** The team's existing tags that aren't on this lead yet — one tap each. */
const suggestedTags = computed(() =>
    props.tags
        .filter(
            (tag) =>
                !form.tags.some(
                    (name) => name.toLowerCase() === tag.name.toLowerCase(),
                ),
        )
        .slice(0, 8),
);

// Bumped on every submit so the error summary announces a repeat rejection rather than
// sitting still because the number of failures happens to be the same.
const attempt = ref(0);

function submit(): void {
    attempt.value += 1;

    form.post('/leads', {
        // The redirect leaves this screen, and by then the draft is saved rather than lost.
        onSuccess: () => guard.allowOnce(),
    });
}
</script>

<template>
    <Head :title="t('Add a lead')" />

    <div class="flex flex-col gap-6 py-2">
        <PageHero
            index="06"
            :eyebrow="t('Add a lead')"
            :title="t('Met someone|good.')"
            :description="
                t(
                    'Give Alima the company name and it will go and find the rest. Everything else here is optional — fill in what you know while you still remember it.',
                )
            "
            size="compact"
        >
            <template #actions>
                <Button variant="hero" as-child>
                    <Link href="/leads">
                        <!-- Mirrored under RTL: the arrow means "back", which follows the
                             way the language reads. -->
                        <ArrowLeft class="size-4 rtl:rotate-180" />
                        {{ t('All leads') }}
                    </Link>
                </Button>
            </template>
        </PageHero>

        <form class="space-y-6" @submit.prevent="submit">
            <FormErrorSummary
                :errors="form.errors"
                :labels="errorLabels"
                :anchors="errorAnchors"
                :attempt="attempt"
            />

            <FormSection step="01" :title="t('The company')">
                <FormField
                    id="company-name"
                    :label="t('Company name')"
                    required
                    :error="form.errors.company_name"
                    :hint="
                        t(
                            'It’s the only thing Alima needs. Already have this company? Alima adds to that lead instead of making a second one.',
                        )
                    "
                    class="lg:max-w-2xl"
                >
                    <template #default="{ id, describedBy, invalid }">
                        <Input
                            :id="id"
                            v-model="form.company_name"
                            name="company_name"
                            required
                            autocomplete="organization"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :placeholder="t('e.g. Bright Bean Coffee')"
                            dir="auto"
                            data-test="company-name"
                        />
                    </template>
                </FormField>

                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField
                        id="website"
                        :label="t('Website')"
                        :error="form.errors.website"
                        :hint="
                            t(
                                'Worth a few seconds if you have it — it is how Alima finds everything else.',
                            )
                        "
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <Input
                                :id="id"
                                v-model="form.website"
                                name="website"
                                autocomplete="url"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                :placeholder="t('brightbean.co.uk')"
                                dir="ltr"
                                data-test="website"
                            />
                        </template>
                    </FormField>

                    <FormField
                        id="company-number"
                        :label="t('Company number')"
                        :error="form.errors.company_number"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <Input
                                :id="id"
                                v-model="form.company_number"
                                name="company_number"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                dir="ltr"
                            />
                        </template>
                    </FormField>

                    <FormField
                        id="industry"
                        :label="t('Industry')"
                        :error="form.errors.industry"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <Input
                                :id="id"
                                v-model="form.industry"
                                name="industry"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                dir="auto"
                            />
                        </template>
                    </FormField>

                    <FormField
                        id="employee-range"
                        :label="t('Team size')"
                        :error="form.errors.employee_range"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <Input
                                :id="id"
                                v-model="form.employee_range"
                                name="employee_range"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                :placeholder="t('e.g. 11-50')"
                                dir="auto"
                            />
                        </template>
                    </FormField>
                </div>
            </FormSection>

            <FormSection step="02" :title="t('Where they are')">
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField
                        id="locality"
                        :label="t('Town or city')"
                        :error="form.errors.locality"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <Input
                                :id="id"
                                v-model="form.locality"
                                name="locality"
                                autocomplete="address-level2"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                dir="auto"
                                data-test="locality"
                            />
                        </template>
                    </FormField>

                    <FormField
                        id="region"
                        :label="t('Region')"
                        :error="form.errors.region"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <Input
                                :id="id"
                                v-model="form.region"
                                name="region"
                                autocomplete="address-level1"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                dir="auto"
                            />
                        </template>
                    </FormField>

                    <FormField
                        id="postal-code"
                        :label="t('Postcode')"
                        :error="form.errors.postal_code"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <Input
                                :id="id"
                                v-model="form.postal_code"
                                name="postal_code"
                                autocomplete="postal-code"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                dir="ltr"
                            />
                        </template>
                    </FormField>

                    <FormField
                        id="country"
                        :label="t('Country')"
                        :error="form.errors.country"
                        :hint="
                            t(
                                'Leave it if they are somewhere else — Alima works it out when it looks them up.',
                            )
                        "
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <NativeSelect
                                :id="id"
                                v-model="form.country"
                                name="country"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                data-test="country"
                            >
                                <option value="">
                                    {{ t('Not sure yet') }}
                                </option>
                                <option
                                    v-for="country in props.countries"
                                    :key="country.code"
                                    :value="country.code"
                                >
                                    {{ t(country.name) }}
                                </option>
                            </NativeSelect>
                        </template>
                    </FormField>
                </div>
            </FormSection>

            <FormSection
                step="03"
                :title="t('Who you spoke to')"
                :description="
                    t(
                        'Optional. If you came away with a card, this is where it goes.',
                    )
                "
            >
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField
                        id="contact-name"
                        :label="t('Their name')"
                        :error="form.errors.contact_name"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <Input
                                :id="id"
                                v-model="form.contact_name"
                                name="contact_name"
                                autocomplete="name"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                dir="auto"
                                data-test="contact-name"
                            />
                        </template>
                    </FormField>

                    <FormField
                        id="contact-title"
                        :label="t('Their role')"
                        :error="form.errors.contact_title"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <Input
                                :id="id"
                                v-model="form.contact_title"
                                name="contact_title"
                                autocomplete="organization-title"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                dir="auto"
                            />
                        </template>
                    </FormField>

                    <FormField
                        id="contact-email"
                        :label="t('Email')"
                        :error="form.errors.contact_email"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <Input
                                :id="id"
                                v-model="form.contact_email"
                                name="contact_email"
                                type="email"
                                autocomplete="email"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                dir="ltr"
                                data-test="contact-email"
                            />
                        </template>
                    </FormField>

                    <FormField
                        id="contact-phone"
                        :label="t('Phone')"
                        :error="form.errors.contact_phone"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <Input
                                :id="id"
                                v-model="form.contact_phone"
                                name="contact_phone"
                                type="tel"
                                autocomplete="tel"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                dir="ltr"
                            />
                        </template>
                    </FormField>
                </div>

                <!-- What the member is agreeing to by typing someone in. Not dismissible. -->
                <template #aside>
                    <div
                        class="flex items-start gap-3 rounded-2xl border border-warning/30 bg-warning/12 p-4"
                        role="note"
                        data-test="contact-notice"
                    >
                        <ShieldCheck
                            class="mt-0.5 size-5 shrink-0 text-warning"
                            aria-hidden="true"
                        />
                        <div class="space-y-0.5">
                            <p
                                class="text-sm font-semibold text-foreground rtl:font-bold"
                            >
                                {{ t('Only details they gave you') }}
                            </p>
                            <!-- `foreground/80`, not `muted`: muted is about 4.4:1 on
                                 this tint, under AA (brand-tokens.md §7f.3). -->
                            <p class="text-sm text-foreground/80">
                                {{
                                    t(
                                        'A card, a conversation or something already public. Alima will not treat this as checked, and your team is responsible for it.',
                                    )
                                }}
                            </p>
                        </div>
                    </div>
                </template>
            </FormSection>

            <FormSection step="04" :title="t('Your workflow')">
                <FormField
                    id="stage"
                    :label="t('Pipeline stage')"
                    :error="form.errors.pipeline_stage_id"
                    class="sm:max-w-xs"
                >
                    <template #default="{ id, describedBy, invalid }">
                        <NativeSelect
                            :id="id"
                            v-model="form.pipeline_stage_id"
                            name="pipeline_stage_id"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            data-test="stage"
                        >
                            <!-- A stage name is the team's own word, never translated. -->
                            <option
                                v-for="stage in props.stages"
                                :key="stage.id"
                                :value="stage.id"
                            >
                                {{ stage.name }}
                            </option>
                        </NativeSelect>
                    </template>
                </FormField>

                <div
                    class="space-y-2"
                    role="group"
                    aria-labelledby="tags-label"
                >
                    <p
                        id="tags-label"
                        class="text-sm font-medium text-foreground"
                    >
                        {{ t('Tags') }}
                    </p>

                    <ul v-if="form.tags.length" class="flex flex-wrap gap-1.5">
                        <li v-for="tag in form.tags" :key="tag">
                            <!-- Decorative taxonomy, never state — hence a lilac chip. -->
                            <Badge variant="lilac" class="gap-1" dir="auto">
                                {{ tag }}
                                <button
                                    type="button"
                                    class="-me-1 flex size-6 items-center justify-center rounded-full focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                    :title="t('Remove')"
                                    :aria-label="
                                        t('Remove the tag “:name”', {
                                            name: tag,
                                        })
                                    "
                                    @click="removeTag(tag)"
                                >
                                    <X class="size-3" />
                                </button>
                            </Badge>
                        </li>
                    </ul>

                    <!-- Always mounted: a live region inserted with text already in it is
                         usually not announced, so the first change would go by in silence. -->
                    <p class="sr-only" role="status" aria-live="polite">
                        {{ tagAnnouncement }}
                    </p>

                    <div ref="tagInput" class="flex gap-2">
                        <Label for="new-tag" class="sr-only">{{
                            t('Add a tag')
                        }}</Label>
                        <Input
                            id="new-tag"
                            v-model="newTag"
                            class="h-9 flex-1 text-sm sm:max-w-xs"
                            maxlength="40"
                            :placeholder="t('e.g. met at expo')"
                            autocomplete="off"
                            dir="auto"
                            data-test="new-tag"
                            @keydown.enter.prevent="addTag(newTag)"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            class="h-9"
                            :disabled="newTag.trim() === ''"
                            @click="addTag(newTag)"
                        >
                            <Plus class="size-4" />
                            {{ t('Add') }}
                        </Button>
                    </div>

                    <!-- One tap for a tag the team already uses. -->
                    <div
                        v-if="suggestedTags.length"
                        class="flex flex-wrap items-center gap-1.5 pt-1"
                    >
                        <span class="text-xs text-muted-foreground">{{
                            t('Already using:')
                        }}</span>
                        <button
                            v-for="tag in suggestedTags"
                            :key="tag.id"
                            type="button"
                            class="rounded-full focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                            :aria-label="
                                t('Add the tag “:name”', { name: tag.name })
                            "
                            @click="addTag(tag.name)"
                        >
                            <Badge
                                variant="outline"
                                class="border-input hover:bg-accent"
                                dir="auto"
                                >{{ tag.name }}</Badge
                            >
                        </button>
                    </div>

                    <p
                        v-if="form.errors.tags"
                        class="text-sm text-destructive"
                        dir="auto"
                    >
                        {{ form.errors.tags }}
                    </p>
                </div>

                <FormField
                    id="note"
                    :label="t('Note')"
                    :error="form.errors.note"
                    :hint="
                        t(
                            'Write it now while it is fresh — it lands on the lead’s timeline.',
                        )
                    "
                    class="lg:max-w-3xl"
                >
                    <template #default="{ id, describedBy, invalid }">
                        <Textarea
                            :id="id"
                            v-model="form.note"
                            name="note"
                            rows="3"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :placeholder="
                                t(
                                    'What you talked about, and what happens next…',
                                )
                            "
                            dir="auto"
                            data-test="note"
                        />
                    </template>
                </FormField>
            </FormSection>

            <FormActions :dirty="form.isDirty" :processing="form.processing">
                <Button variant="outline" as-child>
                    <Link href="/leads">{{ t('Cancel') }}</Link>
                </Button>
                <Button
                    type="submit"
                    variant="brand"
                    :disabled="form.processing"
                    data-test="save-lead"
                >
                    <Plus class="size-4" />
                    {{ form.processing ? t('Adding…') : t('Add this lead') }}
                </Button>
            </FormActions>
        </form>

        <p
            v-if="props.can.import"
            class="flex items-start gap-3 rounded-2xl border border-dashed border-border bg-card/50 p-5 text-sm text-muted-foreground"
        >
            <Upload class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>
                {{ t('Got a whole list of them?') }}
                <Link
                    href="/leads/import"
                    class="font-semibold text-brand underline-offset-4 hover:underline rtl:font-bold"
                >
                    {{ t('Import a CSV instead') }}
                </Link>
            </span>
        </p>

        <UnsavedChangesDialog
            :open="guard.isPrompting.value"
            @stay="guard.stay"
            @discard="guard.discard"
        />
    </div>
</template>
