<script setup lang="ts">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import { cn } from '@/lib/utils';

/*
 * One labelled field: the label, the hint, the error, and the control itself.
 *
 * It exists because the three long forms in the product had written this out by hand forty-odd
 * times between them, and the copies had drifted in the way copies do. In particular **not one of
 * them set `aria-invalid`** — so a field the server had rejected looked wrong and read fine, and a
 * screen-reader user tabbing back through a failed form was given no clue which field to fix. The
 * wiring is the point:
 *
 *  - `for` / `id` are the same string by construction, not by two people typing it twice.
 *  - `aria-describedby` names the hint *and* the error, so both are read with the field rather
 *    than discovered by accident.
 *  - `aria-invalid` follows the error.
 *  - The anchor the error summary links to is this field's own id, so "Company name" in the
 *    summary moves focus into the box.
 *
 * "Required" is said **in words**, never with a red asterisk: Signal Orange is decorative only
 * (brand-tokens.md §2.1), a colour is not a label, and an asterisk is a convention people have to
 * have been taught. The product's forms ask for as little as possible, so the opposite marking —
 * calling out what is *optional* — is usually the more honest one.
 */
const props = withDefaults(
    defineProps<{
        /** The control's id. Also the summary's anchor, so keep it stable. */
        id: string;
        /** Field label, already translated. */
        label: string;
        /** One line under the field: what to type, or why it is worth typing. */
        hint?: string | null;
        /** The server's message for this field, if any. */
        error?: string | null;
        /** Say "(required)" beside the label. */
        required?: boolean;
        /** Say "(optional)" beside the label. */
        optional?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    {
        hint: null,
        error: null,
        required: false,
        optional: false,
        class: '',
    },
);

const { t } = useI18n();

const hintId = computed(() => `${props.id}-hint`);
const errorId = computed(() => `${props.id}-error`);

const describedBy = computed(() => {
    const ids = [
        props.hint ? hintId.value : null,
        props.error ? errorId.value : null,
    ].filter((id): id is string => id !== null);

    return ids.length > 0 ? ids.join(' ') : undefined;
});
</script>

<template>
    <div :class="cn('space-y-2', props.class)">
        <Label :for="props.id" class="flex-wrap">
            {{ props.label }}
            <span
                v-if="props.required"
                class="text-xs font-normal text-muted-foreground"
            >
                {{ t('(required)') }}
            </span>
            <span
                v-else-if="props.optional"
                class="text-xs font-normal text-muted-foreground"
            >
                {{ t('(optional)') }}
            </span>
        </Label>

        <!-- The control. The slot is handed the wiring rather than asked to repeat it. -->
        <slot
            :id="props.id"
            :described-by="describedBy"
            :invalid="props.error ? true : undefined"
        />

        <p
            v-if="props.hint"
            :id="hintId"
            class="text-xs text-muted-foreground"
            dir="auto"
        >
            {{ props.hint }}
        </p>

        <!-- No `role="alert"`: the error is named by `aria-describedby`, and the summary at the
             top of the form is the one thing that announces itself. Two announcements of the same
             failure is one too many. -->
        <p
            v-if="props.error"
            :id="errorId"
            class="text-sm text-destructive"
            dir="auto"
        >
            {{ props.error }}
        </p>
    </div>
</template>
