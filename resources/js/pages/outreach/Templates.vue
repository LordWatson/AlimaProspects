<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ChevronDown,
    FileText,
    Plus,
    ShieldOff,
    Star,
    Trash2,
} from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import { FormErrorSummary, FormField } from '@/components/forms';
import PageHero from '@/components/PageHero.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import type {
    OutreachLocaleOption,
    OutreachTemplate,
    OutreachTemplateForm,
    OutreachToneOption,
} from '@/types';

/*
 * The team's message briefs — the angles they want their outreach to take.
 *
 * The screen deliberately does **not** look like a mail-merge editor. A brief is not a template
 * with `{{first_name}}` holes in it; it is what the team would tell a new salesperson before their
 * first call, written in their own words and handed to the drafting model as context. So the body
 * field is a plain textarea with a prompt-shaped placeholder, and the copy explains that Alima
 * writes the message — a member who expects merge fields and finds none would otherwise think the
 * feature was unfinished.
 *
 * Three things the rebuild fixed, all of them about the form being *above* a list of cards:
 *
 *  - **Deleting asks.** A brief took one click to destroy, with no confirmation and no undo, from a
 *    button sitting a few pixels from "Edit".
 *  - **Editing takes you to the form.** Pressing "Edit" on the sixth card opened the form at the
 *    top of the page and left the member looking at the sixth card. Focus and the viewport now
 *    follow the action, which is the whole contract of an inline form.
 *  - **A long brief no longer owns the page.** The bodies are the point of this screen, but a
 *    400-word one made its card four screens tall and buried the rest. They clamp, with a real
 *    toggle — not hidden, just folded.
 */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Outreach briefs', href: '/outreach/templates' },
        ],
    },
});

const props = defineProps<{
    templates: OutreachTemplate[];
    options: {
        channels: { value: string; label: string; has_subject: boolean }[];
        tones: OutreachToneOption[];
        locales: OutreachLocaleOption[];
    };
    can: { manage: boolean };
}>();

const { t } = useI18n();

/** Null while adding, the brief's id while editing. */
const editing = ref<number | null>(null);
const showForm = ref(false);
const formCard = useTemplateRef<HTMLElement>('formCard');

const form = useForm<OutreachTemplateForm>({
    name: '',
    channel: 'email',
    tone: 'direct',
    locale: 'en',
    subject: '',
    body: '',
    is_default: false,
});

const errorLabels = computed<Record<string, string>>(() => ({
    name: t('Name'),
    channel: t('Channel'),
    tone: t('Tone'),
    locale: t('Language'),
    subject: t('Subject line or angle'),
    body: t('The brief'),
    is_default: t('Use this one first on this channel'),
}));

const errorAnchors: Record<string, string> = {
    name: 'brief-name',
    channel: 'brief-channel',
    tone: 'brief-tone',
    locale: 'brief-locale',
    subject: 'brief-subject',
    body: 'brief-body',
    is_default: 'brief-default',
};

const channelHasSubject = computed(
    () =>
        props.options.channels.find((c) => c.value === form.channel)
            ?.has_subject ?? false,
);

/**
 * Open the form and go there.
 *
 * The form is one card at the top of a page of cards, so opening it from the bottom of the list
 * otherwise changes something the member cannot see. Focus lands in the first field, which is both
 * the courtesy and the accessible behaviour: a form that appears without focus is a form a screen
 * reader never mentions.
 */
async function openForm(): Promise<void> {
    showForm.value = true;

    await nextTick();
    formCard.value?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    formCard.value?.querySelector<HTMLInputElement>('#brief-name')?.focus();
}

function startAdding(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    void openForm();
}

function startEditing(template: OutreachTemplate): void {
    editing.value = template.id;
    form.clearErrors();
    form.name = template.name;
    form.channel = template.channel;
    form.tone = template.tone;
    form.locale = template.locale;
    form.subject = template.subject ?? '';
    form.body = template.body;
    form.is_default = template.is_default;
    void openForm();
}

// Bumped on every submit so the error summary announces a repeat rejection rather than
// sitting still because the number of failures happens to be the same.
const attempt = ref(0);

function submit(): void {
    attempt.value += 1;

    const onSuccess = () => {
        showForm.value = false;
        editing.value = null;
        form.reset();
    };

    if (editing.value === null) {
        form.post('/outreach/templates', { preserveScroll: true, onSuccess });

        return;
    }

    form.patch(`/outreach/templates/${editing.value}`, {
        preserveScroll: true,
        onSuccess,
    });
}

/** Which brief is being asked about before it is destroyed. */
const confirmingDelete = ref<number | null>(null);

function remove(template: OutreachTemplate): void {
    router.delete(`/outreach/templates/${template.id}`, {
        preserveScroll: true,
        onFinish: () => (confirmingDelete.value = null),
    });
}

// --- Folded bodies -------------------------------------------------------------------------
// A brief is prose, and some are long. They are folded by default and opened one at a time.
const expanded = ref<number[]>([]);

function isExpanded(template: OutreachTemplate): boolean {
    return expanded.value.includes(template.id);
}

function toggleBody(template: OutreachTemplate): void {
    expanded.value = isExpanded(template)
        ? expanded.value.filter((id) => id !== template.id)
        : [...expanded.value, template.id];
}

/** Grouped by channel, because a brief is written for one channel and read next to its siblings. */
const grouped = computed(() => {
    const groups = new Map<
        string,
        { label: string; templates: OutreachTemplate[] }
    >();

    for (const channel of props.options.channels) {
        const templates = props.templates.filter(
            (template) => template.channel === channel.value,
        );

        if (templates.length > 0) {
            groups.set(channel.value, { label: channel.label, templates });
        }
    }

    return [...groups.values()];
});
</script>

<template>
    <Head :title="t('Outreach briefs')" />

    <div class="flex flex-col gap-6 py-2">
        <!-- This is the destination behind sidebar item 08, so it opens with the hero every
             main screen opens with (brand-tokens.md §5). Its siblings — the per-lead draft
             screen and the do-not-contact list — are sub-pages reached from here or from a
             lead, and use plain headers like `leads/Create.vue` does. -->
        <PageHero
            index="08"
            :eyebrow="t('Outreach')"
            :title="t('Say it the way|your team says it.')"
            :description="
                t(
                    'Tell Alima how your team talks to prospects, and every draft follows it. Not a template with blanks to fill — a brief, in your own words.',
                )
            "
            size="compact"
        >
            <template #actions>
                <Button v-if="can.manage" variant="lime" @click="startAdding">
                    <Plus class="size-4" />
                    {{ t('New brief') }}
                </Button>
                <Button variant="hero" as-child>
                    <Link href="/outreach/do-not-contact">
                        <ShieldOff class="size-4" aria-hidden="true" />
                        {{ t('Do not contact') }}
                    </Link>
                </Button>
            </template>
        </PageHero>

        <!-- The form -->
        <section
            v-if="showForm"
            ref="formCard"
            class="scroll-mt-6 space-y-4 rounded-2xl border border-brand/40 bg-card p-6"
            data-test="brief-form"
        >
            <h2
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
            >
                {{ editing === null ? t('New brief') : t('Edit brief') }}
            </h2>

            <form class="space-y-5" @submit.prevent="submit">
                <FormErrorSummary
                    :errors="form.errors"
                    :labels="errorLabels"
                    :anchors="errorAnchors"
                    :attempt="attempt"
                />

                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField
                        id="brief-name"
                        :label="t('Name')"
                        required
                        :error="form.errors.name"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <Input
                                :id="id"
                                v-model="form.name"
                                required
                                dir="auto"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                :placeholder="t('e.g. First touch')"
                                data-test="brief-name"
                            />
                        </template>
                    </FormField>

                    <FormField
                        id="brief-channel"
                        :label="t('Channel')"
                        :error="form.errors.channel"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <NativeSelect
                                :id="id"
                                v-model="form.channel"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                            >
                                <option
                                    v-for="channel in options.channels"
                                    :key="channel.value"
                                    :value="channel.value"
                                >
                                    {{ t(channel.label) }}
                                </option>
                            </NativeSelect>
                        </template>
                    </FormField>

                    <FormField
                        id="brief-tone"
                        :label="t('Tone')"
                        :error="form.errors.tone"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <NativeSelect
                                :id="id"
                                v-model="form.tone"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                            >
                                <option
                                    v-for="tone in options.tones"
                                    :key="tone.value"
                                    :value="tone.value"
                                >
                                    {{ t(tone.label) }}
                                </option>
                            </NativeSelect>
                        </template>
                    </FormField>

                    <!-- One brief per language rather than one that reads oddly in the other. -->
                    <FormField
                        id="brief-locale"
                        :label="t('Language')"
                        :error="form.errors.locale"
                        :hint="t('Keep one brief per language.')"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <NativeSelect
                                :id="id"
                                v-model="form.locale"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                            >
                                <option
                                    v-for="locale in options.locales"
                                    :key="locale.value"
                                    :value="locale.value"
                                >
                                    {{ locale.label }}
                                </option>
                            </NativeSelect>
                        </template>
                    </FormField>
                </div>

                <FormField
                    v-if="channelHasSubject"
                    id="brief-subject"
                    :label="t('Subject line or angle')"
                    optional
                    :error="form.errors.subject"
                    class="lg:max-w-2xl"
                >
                    <template #default="{ id, describedBy, invalid }">
                        <Input
                            :id="id"
                            v-model="form.subject"
                            dir="auto"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                        />
                    </template>
                </FormField>

                <FormField
                    id="brief-body"
                    :label="t('The brief')"
                    required
                    :error="form.errors.body"
                    :hint="
                        t(
                            'Alima writes the message — this is what you would tell a new salesperson before their first call.',
                        )
                    "
                    class="lg:max-w-3xl"
                >
                    <template #default="{ id, describedBy, invalid }">
                        <Textarea
                            :id="id"
                            v-model="form.body"
                            rows="6"
                            required
                            dir="auto"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :placeholder="
                                t(
                                    'e.g. Lead with how long their onboarding takes. Mention our two-week setup. Ask for 15 minutes, never for a sale. Never promise a discount.',
                                )
                            "
                            data-test="brief-body"
                        />
                    </template>
                </FormField>

                <div class="flex items-start gap-3">
                    <Checkbox
                        id="brief-default"
                        v-model="form.is_default"
                        data-test="brief-default"
                    />
                    <div class="space-y-1">
                        <Label for="brief-default" class="font-normal">
                            {{ t('Use this one first on this channel') }}
                        </Label>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'Only one brief per channel can be the first choice.',
                                )
                            }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button
                        type="submit"
                        variant="brand"
                        :disabled="form.processing"
                        data-test="brief-save"
                    >
                        {{ form.processing ? t('Saving…') : t('Save') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        @click="showForm = false"
                    >
                        {{ t('Cancel') }}
                    </Button>
                </div>
            </form>
        </section>

        <!-- The briefs -->
        <div v-if="templates.length" class="space-y-8">
            <section
                v-for="group in grouped"
                :key="group.label"
                class="space-y-3"
            >
                <h2
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t(group.label) }}
                </h2>

                <ul class="grid gap-4 lg:grid-cols-2">
                    <li
                        v-for="template in group.templates"
                        :key="template.id"
                        class="flex flex-col gap-3 rounded-2xl border bg-card p-5"
                        :class="
                            template.is_default
                                ? 'border-lime'
                                : 'border-border'
                        "
                        :data-test="`brief-${template.id}`"
                    >
                        <header
                            class="flex flex-wrap items-start justify-between gap-2"
                        >
                            <div class="min-w-0">
                                <p
                                    class="truncate font-semibold text-foreground"
                                    dir="auto"
                                >
                                    {{ template.name }}
                                </p>
                                <div
                                    class="mt-1 flex flex-wrap items-center gap-1.5"
                                >
                                    <Badge variant="outline">
                                        {{ t(template.tone_label) }}
                                    </Badge>
                                    <Badge variant="secondary">
                                        {{ template.locale.toUpperCase() }}
                                    </Badge>
                                    <Badge
                                        v-if="template.is_default"
                                        variant="lime"
                                    >
                                        <Star
                                            class="size-3"
                                            aria-hidden="true"
                                        />
                                        {{ t('First choice') }}
                                    </Badge>
                                </div>
                            </div>

                            <div class="flex gap-1">
                                <Button
                                    v-if="template.can_update"
                                    size="sm"
                                    variant="ghost"
                                    @click="startEditing(template)"
                                >
                                    {{ t('Edit') }}
                                </Button>
                                <Button
                                    v-if="template.can_delete"
                                    size="icon-sm"
                                    variant="ghost"
                                    class="text-muted-foreground hover:text-destructive"
                                    :aria-label="
                                        t('Delete :name', {
                                            name: template.name,
                                        })
                                    "
                                    :data-test="`delete-brief-${template.id}`"
                                    @click="confirmingDelete = template.id"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </header>

                        <!-- Destroying a brief asks first: there is no undo, and the button sits
                             beside "Edit". -->
                        <div
                            v-if="confirmingDelete === template.id"
                            class="flex flex-wrap items-center gap-2 rounded-xl border border-destructive/30 bg-destructive/12 p-3"
                            role="alert"
                        >
                            <p class="flex-1 text-sm text-foreground">
                                {{
                                    t(
                                        'Delete this brief? Drafts already sent are not affected.',
                                    )
                                }}
                            </p>
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="confirmingDelete = null"
                            >
                                {{ t('Cancel') }}
                            </Button>
                            <Button
                                size="sm"
                                variant="destructive"
                                :data-test="`confirm-delete-brief-${template.id}`"
                                @click="remove(template)"
                            >
                                {{ t('Delete') }}
                            </Button>
                        </div>

                        <p
                            v-if="template.subject"
                            class="text-sm font-medium text-foreground"
                            dir="auto"
                        >
                            {{ template.subject }}
                        </p>

                        <p
                            :id="`brief-body-${template.id}`"
                            class="text-sm whitespace-pre-line text-muted-foreground"
                            :class="isExpanded(template) ? '' : 'line-clamp-5'"
                            dir="auto"
                        >
                            {{ template.body }}
                        </p>

                        <button
                            type="button"
                            class="-mt-1 inline-flex items-center gap-1 self-start rounded-sm text-xs font-medium text-brand underline-offset-4 hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                            :aria-expanded="isExpanded(template)"
                            :aria-controls="`brief-body-${template.id}`"
                            @click="toggleBody(template)"
                        >
                            {{
                                isExpanded(template)
                                    ? t('Show less')
                                    : t('Read the whole brief')
                            }}
                            <ChevronDown
                                class="size-3.5 transition-transform"
                                :class="
                                    isExpanded(template) ? 'rotate-180' : ''
                                "
                                aria-hidden="true"
                            />
                        </button>
                    </li>
                </ul>
            </section>
        </div>

        <EmptyState
            v-else
            :icon="FileText"
            :title="t('No briefs yet')"
            :description="
                t(
                    'Alima can draft without one. A brief is for when you want every message to make the same argument.',
                )
            "
            data-test="no-briefs"
        >
            <Button v-if="can.manage" variant="brand" @click="startAdding">
                <Plus class="size-4" />
                {{ t('New brief') }}
            </Button>
        </EmptyState>
    </div>
</template>
