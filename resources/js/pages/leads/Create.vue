<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Plus, ShieldCheck, Upload, X } from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
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

// --- Tags ----------------------------------------------------------------------------------
// Names rather than ids, matching the lead's work panel: a tag is created on first use, so the
// screen never has to create one and then attach it.
const newTag = ref('');
const tagInput = useTemplateRef<InstanceType<typeof Input>>('tagInput');

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
    tagInput.value?.$el?.focus?.();
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

function submit(): void {
    form.post('/leads');
}
</script>

<template>
    <Head :title="t('Add a lead')" />

    <div class="flex flex-col gap-8 py-2">
        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div class="space-y-2">
                <p
                    class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
                >
                    {{ t('Add a lead') }}
                </p>
                <h1
                    class="text-3xl font-semibold tracking-tight text-foreground sm:text-4xl rtl:font-bold"
                >
                    {{ t('Met someone good')
                    }}<span class="text-orange">.</span>
                </h1>
                <p class="max-w-2xl text-base text-muted-foreground">
                    {{
                        t(
                            'Give Alima the company name and it will go and find the rest. Everything else here is optional — fill in what you know while you still remember it.',
                        )
                    }}
                </p>
            </div>

            <Button variant="outline" as-child>
                <Link href="/leads">
                    <!-- Mirrored under RTL: the arrow means "back", which follows the
                         way the language reads. -->
                    <ArrowLeft class="size-4 rtl:rotate-180" />
                    {{ t('All leads') }}
                </Link>
            </Button>
        </header>

        <form class="space-y-6" @submit.prevent="submit">
            <!-- The company -->
            <section
                class="space-y-4 rounded-2xl border border-border bg-card p-6"
            >
                <h2
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('The company') }}
                </h2>

                <div class="space-y-2">
                    <Label for="company-name">
                        {{ t('Company name') }}
                        <!-- Signal Orange is decorative only (brand-tokens §2.1), and
                             "required" is functional — so it is said in words. -->
                        <span
                            class="text-xs font-normal text-muted-foreground"
                            >{{ t('(required)') }}</span
                        >
                    </Label>
                    <Input
                        id="company-name"
                        v-model="form.company_name"
                        name="company_name"
                        required
                        autocomplete="organization"
                        aria-describedby="company-name-hint"
                        class="lg:max-w-2xl"
                        :placeholder="t('e.g. Bright Bean Coffee')"
                        dir="auto"
                        data-test="company-name"
                    />
                    <p
                        id="company-name-hint"
                        class="text-xs text-muted-foreground lg:max-w-2xl"
                    >
                        {{
                            t(
                                'It’s the only thing Alima needs. Already have this company? Alima adds to that lead instead of making a second one.',
                            )
                        }}
                    </p>
                    <InputError :message="form.errors.company_name" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="space-y-2">
                        <Label for="website">{{ t('Website') }}</Label>
                        <Input
                            id="website"
                            v-model="form.website"
                            name="website"
                            autocomplete="url"
                            aria-describedby="website-hint"
                            :placeholder="t('brightbean.co.uk')"
                            dir="ltr"
                            data-test="website"
                        />
                        <p
                            id="website-hint"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                t(
                                    'Worth a few seconds if you have it — it is how Alima finds everything else.',
                                )
                            }}
                        </p>
                        <InputError :message="form.errors.website" />
                    </div>
                    <div class="space-y-2">
                        <Label for="company-number">{{
                            t('Company number')
                        }}</Label>
                        <Input
                            id="company-number"
                            v-model="form.company_number"
                            name="company_number"
                            dir="ltr"
                        />
                        <InputError :message="form.errors.company_number" />
                    </div>
                    <div class="space-y-2">
                        <Label for="industry">{{ t('Industry') }}</Label>
                        <Input
                            id="industry"
                            v-model="form.industry"
                            name="industry"
                            dir="auto"
                        />
                        <InputError :message="form.errors.industry" />
                    </div>
                    <div class="space-y-2">
                        <Label for="employee-range">{{ t('Team size') }}</Label>
                        <Input
                            id="employee-range"
                            v-model="form.employee_range"
                            name="employee_range"
                            :placeholder="t('e.g. 11-50')"
                            dir="auto"
                        />
                        <InputError :message="form.errors.employee_range" />
                    </div>
                </div>
            </section>

            <!-- Where they are -->
            <section
                class="space-y-4 rounded-2xl border border-border bg-card p-6"
            >
                <h2
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Where they are') }}
                </h2>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="space-y-2">
                        <Label for="locality">{{ t('Town or city') }}</Label>
                        <Input
                            id="locality"
                            v-model="form.locality"
                            name="locality"
                            autocomplete="address-level2"
                            dir="auto"
                            data-test="locality"
                        />
                        <InputError :message="form.errors.locality" />
                    </div>
                    <div class="space-y-2">
                        <Label for="region">{{ t('Region') }}</Label>
                        <Input
                            id="region"
                            v-model="form.region"
                            name="region"
                            autocomplete="address-level1"
                            dir="auto"
                        />
                        <InputError :message="form.errors.region" />
                    </div>
                    <div class="space-y-2">
                        <Label for="postal-code">{{ t('Postcode') }}</Label>
                        <Input
                            id="postal-code"
                            v-model="form.postal_code"
                            name="postal_code"
                            autocomplete="postal-code"
                            dir="ltr"
                        />
                        <InputError :message="form.errors.postal_code" />
                    </div>
                    <div class="space-y-2">
                        <Label for="country">{{ t('Country') }}</Label>
                        <NativeSelect
                            id="country"
                            v-model="form.country"
                            name="country"
                            aria-describedby="country-hint"
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
                        <p
                            id="country-hint"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                t(
                                    'Leave it if they are somewhere else — Alima works it out when it looks them up.',
                                )
                            }}
                        </p>
                        <InputError :message="form.errors.country" />
                    </div>
                </div>
            </section>

            <!-- Who you spoke to -->
            <section
                class="space-y-4 rounded-2xl border border-border bg-card p-6"
            >
                <div class="space-y-1">
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Who you spoke to') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'Optional. If you came away with a card, this is where it goes.',
                            )
                        }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="space-y-2">
                        <Label for="contact-name">{{ t('Their name') }}</Label>
                        <Input
                            id="contact-name"
                            v-model="form.contact_name"
                            name="contact_name"
                            autocomplete="name"
                            dir="auto"
                            data-test="contact-name"
                        />
                        <InputError :message="form.errors.contact_name" />
                    </div>
                    <div class="space-y-2">
                        <Label for="contact-title">{{ t('Their role') }}</Label>
                        <Input
                            id="contact-title"
                            v-model="form.contact_title"
                            name="contact_title"
                            autocomplete="organization-title"
                            dir="auto"
                        />
                        <InputError :message="form.errors.contact_title" />
                    </div>
                    <div class="space-y-2">
                        <Label for="contact-email">{{ t('Email') }}</Label>
                        <Input
                            id="contact-email"
                            v-model="form.contact_email"
                            name="contact_email"
                            type="email"
                            autocomplete="email"
                            dir="ltr"
                            data-test="contact-email"
                        />
                        <InputError :message="form.errors.contact_email" />
                    </div>
                    <div class="space-y-2">
                        <Label for="contact-phone">{{ t('Phone') }}</Label>
                        <Input
                            id="contact-phone"
                            v-model="form.contact_phone"
                            name="contact_phone"
                            type="tel"
                            autocomplete="tel"
                            dir="ltr"
                        />
                        <InputError :message="form.errors.contact_phone" />
                    </div>
                </div>

                <!-- What the member is agreeing to by typing someone in. Not dismissible. -->
                <div
                    class="flex items-start gap-3 rounded-2xl bg-warning/12 p-4"
                    role="note"
                    data-test="contact-notice"
                >
                    <span
                        class="flex size-8 shrink-0 items-center justify-center rounded-full text-warning"
                        aria-hidden="true"
                    >
                        <ShieldCheck class="size-4" />
                    </span>
                    <div class="space-y-0.5">
                        <p
                            class="text-sm font-semibold text-foreground rtl:font-bold"
                        >
                            {{ t('Only details they gave you') }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{
                                t(
                                    'A card, a conversation or something already public. Alima will not treat this as checked, and your team is responsible for it.',
                                )
                            }}
                        </p>
                    </div>
                </div>
            </section>

            <!-- Your workflow -->
            <section
                class="space-y-4 rounded-2xl border border-border bg-card p-6"
            >
                <h2
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Your workflow') }}
                </h2>

                <div class="space-y-2">
                    <Label for="stage">{{ t('Pipeline stage') }}</Label>
                    <NativeSelect
                        id="stage"
                        v-model="form.pipeline_stage_id"
                        name="pipeline_stage_id"
                        class="sm:max-w-xs"
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
                    <InputError :message="form.errors.pipeline_stage_id" />
                </div>

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

                    <div class="flex gap-2">
                        <Label for="new-tag" class="sr-only">{{
                            t('Add a tag')
                        }}</Label>
                        <Input
                            id="new-tag"
                            ref="tagInput"
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
                            <Badge variant="outline" dir="auto">{{
                                tag.name
                            }}</Badge>
                        </button>
                    </div>

                    <InputError :message="form.errors.tags" />
                </div>

                <div class="space-y-2">
                    <Label for="note">{{ t('Note') }}</Label>
                    <Textarea
                        id="note"
                        v-model="form.note"
                        name="note"
                        rows="3"
                        aria-describedby="note-hint"
                        class="lg:max-w-3xl"
                        :placeholder="
                            t('What you talked about, and what happens next…')
                        "
                        dir="auto"
                        data-test="note"
                    />
                    <p id="note-hint" class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Write it now while it is fresh — it lands on the lead’s timeline.',
                            )
                        }}
                    </p>
                    <InputError :message="form.errors.note" />
                </div>
            </section>

            <div class="flex flex-wrap items-center justify-end gap-3">
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
            </div>
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
    </div>
</template>
