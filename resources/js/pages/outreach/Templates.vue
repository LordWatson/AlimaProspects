<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { FileText, Plus, ShieldOff, Star, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
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
 * Brand: cream page, one card per brief, Acid Lime on the default marker. The "written by your
 * team" note uses `status.warning`, never Signal Orange (brand-tokens.md §2.1).
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

const form = useForm<OutreachTemplateForm>({
    name: '',
    channel: 'email',
    tone: 'direct',
    locale: 'en',
    subject: '',
    body: '',
    is_default: false,
});

const channelHasSubject = computed(
    () =>
        props.options.channels.find((c) => c.value === form.channel)
            ?.has_subject ?? false,
);

function startAdding(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
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
    showForm.value = true;
}

function submit(): void {
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

function remove(template: OutreachTemplate): void {
    router.delete(`/outreach/templates/${template.id}`, {
        preserveScroll: true,
    });
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
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-2xl">
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Outreach') }}
                </p>
                <h1 class="mt-1 text-2xl font-semibold text-foreground">
                    {{ t('Briefs') }}
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{
                        t(
                            'Tell Alima how your team talks to prospects, and every draft follows it. Not a template with blanks to fill — a brief, in your own words.',
                        )
                    }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link href="/outreach/do-not-contact">
                        <ShieldOff class="size-4" />
                        {{ t('Do not contact') }}
                    </Link>
                </Button>
                <Button
                    v-if="can.manage"
                    data-test="add-brief"
                    @click="startAdding"
                >
                    <Plus class="size-4" />
                    {{ t('New brief') }}
                </Button>
            </div>
        </header>

        <!-- The form -->
        <section
            v-if="showForm"
            class="space-y-4 rounded-2xl border border-border bg-card p-6"
            data-test="brief-form"
        >
            <h2
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
            >
                {{ editing === null ? t('New brief') : t('Edit brief') }}
            </h2>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="space-y-2">
                        <Label for="brief-name">{{ t('Name') }}</Label>
                        <Input
                            id="brief-name"
                            v-model="form.name"
                            required
                            dir="auto"
                            :placeholder="t('e.g. First touch')"
                            data-test="brief-name"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="space-y-2">
                        <Label for="brief-channel">{{ t('Channel') }}</Label>
                        <NativeSelect id="brief-channel" v-model="form.channel">
                            <option
                                v-for="channel in options.channels"
                                :key="channel.value"
                                :value="channel.value"
                            >
                                {{ t(channel.label) }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.channel" />
                    </div>

                    <div class="space-y-2">
                        <Label for="brief-tone">{{ t('Tone') }}</Label>
                        <NativeSelect id="brief-tone" v-model="form.tone">
                            <option
                                v-for="tone in options.tones"
                                :key="tone.value"
                                :value="tone.value"
                            >
                                {{ t(tone.label) }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.tone" />
                    </div>

                    <div class="space-y-2">
                        <Label for="brief-locale">{{ t('Language') }}</Label>
                        <NativeSelect id="brief-locale" v-model="form.locale">
                            <option
                                v-for="locale in options.locales"
                                :key="locale.value"
                                :value="locale.value"
                            >
                                {{ locale.label }}
                            </option>
                        </NativeSelect>
                        <!-- One brief per language rather than one that reads oddly in the other. -->
                        <p class="text-xs text-muted-foreground">
                            {{ t('Keep one brief per language.') }}
                        </p>
                    </div>
                </div>

                <div v-if="channelHasSubject" class="space-y-2">
                    <Label for="brief-subject">
                        {{ t('Subject line or angle') }}
                        <span class="text-xs font-normal text-muted-foreground">
                            {{ t('(optional)') }}
                        </span>
                    </Label>
                    <Input
                        id="brief-subject"
                        v-model="form.subject"
                        class="lg:max-w-2xl"
                        dir="auto"
                    />
                    <InputError :message="form.errors.subject" />
                </div>

                <div class="space-y-2">
                    <Label for="brief-body">{{ t('The brief') }}</Label>
                    <Textarea
                        id="brief-body"
                        v-model="form.body"
                        rows="6"
                        required
                        class="lg:max-w-3xl"
                        dir="auto"
                        :placeholder="
                            t(
                                'e.g. Lead with how long their onboarding takes. Mention our two-week setup. Ask for 15 minutes, never for a sale. Never promise a discount.',
                            )
                        "
                        data-test="brief-body"
                    />
                    <p class="text-xs text-muted-foreground lg:max-w-3xl">
                        {{
                            t(
                                'Alima writes the message — this is what you would tell a new salesperson before their first call.',
                            )
                        }}
                    </p>
                    <InputError :message="form.errors.body" />
                </div>

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
                        :disabled="form.processing"
                        data-test="brief-save"
                    >
                        {{ t('Save') }}
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
                        class="space-y-3 rounded-2xl border border-border bg-card p-5"
                        :class="template.is_default ? 'border-lime' : ''"
                    >
                        <header
                            class="flex flex-wrap items-start justify-between gap-2"
                        >
                            <div class="min-w-0">
                                <p
                                    class="truncate font-semibold text-foreground"
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
                                    :aria-label="t('Delete')"
                                    @click="remove(template)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </header>

                        <p
                            v-if="template.subject"
                            class="text-sm font-medium text-foreground"
                            dir="auto"
                        >
                            {{ template.subject }}
                        </p>

                        <p
                            class="text-sm whitespace-pre-line text-muted-foreground"
                            dir="auto"
                        >
                            {{ template.body }}
                        </p>
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
            <Button v-if="can.manage" @click="startAdding">
                <Plus class="size-4" />
                {{ t('New brief') }}
            </Button>
        </EmptyState>
    </div>
</template>
