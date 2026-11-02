<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';
import { computed, nextTick, useTemplateRef, watch } from 'vue';
import { useI18n } from '@/composables/useI18n';

/*
 * What went wrong, at the top of the form, with a way to get to each field.
 *
 * This is the thing a long form cannot do without. The add-a-lead screen is four sections tall;
 * when the server rejected an email address, the only sign was a red line in the third section,
 * off-screen, with nothing at the top of the page to say so and nothing moving focus anywhere near
 * it. On a phone the member saw the form reload, looked the same, and tried again.
 *
 * So: one panel, `role="alert"` so it announces itself once, focused and scrolled to when it
 * appears, and every failure a link into the field that caused it.
 *
 * It follows the status-panel rules the admin panel wrote down (brand-tokens.md §7f.2–3): a 12%
 * tint of `status.error` with a 30% border, and the **words stay `text-foreground`** — the status
 * colour is kept to the icon, the border and the tint, because `destructive` over a tint of itself
 * does not clear AA at this size.
 */
const props = withDefaults(
    defineProps<{
        /** Inertia's `form.errors`. */
        errors: Record<string, string | undefined>;
        /**
         * Field key → the label to show, already translated. A key with no entry falls back to the
         * server's message alone, which is still better than nothing.
         */
        labels?: Record<string, string>;
        /**
         * Field key → the id of the control to focus. Defaults to the key with underscores turned
         * into hyphens, which is the convention the forms already follow (`company_name` →
         * `company-name`).
         */
        anchors?: Record<string, string>;
        /**
         * A counter the page bumps on every submit.
         *
         * Without it the panel only reacts to the *number* of failures changing, so a
         * second submit rejected for the same two reasons would move no focus and announce
         * nothing — the member would see the page sit still and try again.
         */
        attempt?: number;
    }>(),
    { labels: () => ({}), anchors: () => ({}), attempt: 0 },
);

const { t } = useI18n();

const panel = useTemplateRef<HTMLElement>('panel');

type Problem = {
    key: string;
    label: string | null;
    message: string;
    anchor: string;
};

const problems = computed<Problem[]>(() =>
    Object.entries(props.errors)
        .filter((entry): entry is [string, string] => Boolean(entry[1]))
        .map(([key, message]) => ({
            key,
            label: props.labels[key] ?? null,
            message,
            anchor: props.anchors[key] ?? key.replaceAll('_', '-'),
        })),
);

const heading = computed(() =>
    problems.value.length === 1
        ? t('One thing needs fixing')
        : t(':count things need fixing', { count: problems.value.length }),
);

// Appearing is not enough: a form that failed has to *take* the member somewhere. The panel is
// focusable only programmatically (`tabindex="-1"`), so it is announced and read on arrival
// without becoming a tab stop afterwards. Keyed on the attempt as well as the failures, so the
// same rejection twice is announced twice rather than the page sitting still; immediate, because
// a form can also arrive already carrying errors after a full page load.
watch(
    () => [props.attempt, problems.value.length].join(':'),
    async (key, previous) => {
        if (problems.value.length === 0 || key === previous) return;

        await nextTick();
        panel.value?.focus();
        panel.value?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    },
    { immediate: true },
);

function focusField(anchor: string): void {
    const field = document.getElementById(anchor);

    if (!field) return;

    field.scrollIntoView({ block: 'center', behavior: 'smooth' });
    field.focus();
}
</script>

<template>
    <div
        v-if="problems.length"
        ref="panel"
        role="alert"
        tabindex="-1"
        class="flex items-start gap-3 rounded-2xl border border-destructive/30 bg-destructive/12 p-4 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
        data-test="error-summary"
    >
        <CircleAlert
            class="mt-0.5 size-5 shrink-0 text-destructive"
            aria-hidden="true"
        />
        <div class="min-w-0 space-y-2">
            <p class="text-sm font-semibold text-foreground rtl:font-bold">
                {{ heading }}
            </p>
            <ul class="space-y-1">
                <li
                    v-for="problem in problems"
                    :key="problem.key"
                    class="text-sm text-foreground"
                >
                    <a
                        :href="`#${problem.anchor}`"
                        class="rounded-sm underline decoration-destructive/40 underline-offset-4 hover:decoration-destructive focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        @click.prevent="focusField(problem.anchor)"
                    >
                        <template v-if="problem.label">
                            <span class="font-medium">{{ problem.label }}</span>
                            —
                        </template>
                        <span dir="auto">{{ problem.message }}</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</template>
