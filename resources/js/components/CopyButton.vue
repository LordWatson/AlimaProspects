<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { useI18n } from '@/composables/useI18n';

/*
 * Copy one value to the clipboard.
 *
 * It exists for the contacts table. An email address a member has come to this screen to *use* was
 * something they had to select by hand, character by character, across a table cell — and on a
 * phone, selecting text inside a table row is close to impossible. One tap instead.
 *
 * Three details worth keeping:
 *
 *  - **It only appears when the browser will allow it.** The Clipboard API needs a secure context,
 *    so the check happens on mount rather than at render time (there is an SSR build) and the
 *    button simply isn't there when it would fail. A button that does nothing is worse than no
 *    button.
 *  - **It is bigger than it looks.** The glyph is 24px, which only just meets WCAG 2.5.8, and
 *    this exists for the phone — so an invisible `before` pseudo-element takes the hit area to
 *    about 40px without moving the layout around it.
 *  - **It says what it did, twice.** A toast for everyone, and the icon turns to a tick for a
 *    moment — because on a phone the toast may be the only thing off-screen. The accessible name
 *    names the value's *kind* ("Copy email address"), never the value itself: twenty rows of
 *    "Copy name@acme.com" is twenty unreadable button names.
 */
const props = defineProps<{
    /** The text to copy. */
    value: string;
    /** What kind of thing this is, already translated — "email address", "phone number". */
    label: string;
}>();

const { t } = useI18n();

const canCopy = ref(false);
const copied = ref(false);

let timer: ReturnType<typeof setTimeout> | undefined;

onMounted(() => {
    canCopy.value =
        typeof navigator !== 'undefined' &&
        typeof navigator.clipboard?.writeText === 'function';
});

onBeforeUnmount(() => clearTimeout(timer));

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.value);
    } catch {
        // Permission can still be refused at the moment of asking, whatever the capability check
        // said — a denied clipboard prompt, or a page that has lost focus.
        toast.error(t('Your browser wouldn’t let Alima copy that.'));

        return;
    }

    copied.value = true;
    toast.success(t('Copied the :label', { label: props.label }));

    clearTimeout(timer);
    timer = setTimeout(() => (copied.value = false), 2000);
}
</script>

<template>
    <button
        v-if="canCopy"
        type="button"
        class="relative inline-flex size-6 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors before:absolute before:-inset-2 hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
        :aria-label="t('Copy the :label', { label: props.label })"
        :title="t('Copy the :label', { label: props.label })"
        @click="copy"
    >
        <component
            :is="copied ? Check : Copy"
            class="size-3.5"
            aria-hidden="true"
        />
    </button>
</template>
