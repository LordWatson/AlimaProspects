<script setup lang="ts">
import { Search, X } from '@lucide/vue';
import { onBeforeUnmount, ref, useTemplateRef, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';

/*
 * The search box every list screen uses.
 *
 * Two things it settles so no screen has to settle them again:
 *
 *  - **Debounced.** One request when the typing stops, not one per keystroke.
 *  - **Clearable.** A search you cannot get out of is a filter you cannot see. The clear button
 *    fires immediately — waiting 350ms to undo something is the one case where the debounce is
 *    simply wrong — and hands focus back to the field, where the member already was. Escape
 *    clears it too, and does nothing at all on an already-empty box rather than firing a request
 *    that changes nothing.
 *
 * It had a **`/` shortcut**, with the hint shown in the field, and that was withdrawn: a
 * single-character shortcut bound to the document fails WCAG 2.1.4 (Level A) unless it can be
 * turned off, remapped, or is active only while the component has focus. Guarding it against
 * fields and dialogs — which it did — is none of those three. It can come back the way GitHub's
 * does, as a preference a member can switch off; it cannot come back as a global listener with no
 * way out of it (brand-tokens.md §7h).
 */
const props = withDefaults(
    defineProps<{
        /** The term in force, from the server. The box follows it when the server changes it. */
        value: string;
        /** Field label, already translated. Visible by default — a placeholder is not a label. */
        label: string;
        placeholder?: string;
        id?: string;
        debounceMs?: number;
        dataTest?: string | null;
    }>(),
    {
        placeholder: '',
        id: 'list-search',
        debounceMs: 350,
        dataTest: null,
    },
);

const emit = defineEmits<{ search: [term: string] }>();

const { t } = useI18n();

const text = ref(props.value);
const field = useTemplateRef<HTMLElement>('field');

let timer: ReturnType<typeof setTimeout> | undefined;

// Keep the box in step when the server sends a different term — a saved view was opened, or the
// member used the back button.
watch(
    () => props.value,
    (value) => {
        if (value !== text.value) text.value = value;
    },
);

watch(text, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => emit('search', value), props.debounceMs);
});

onBeforeUnmount(() => clearTimeout(timer));

function focus(): void {
    field.value?.querySelector('input')?.focus();
}

function clear(): void {
    clearTimeout(timer);

    // Escape on an already-empty box would otherwise fire a request that changes nothing.
    if (text.value === '') {
        focus();

        return;
    }

    text.value = '';
    emit('search', '');
    focus();
}
</script>

<template>
    <div class="grid gap-2">
        <Label :for="props.id">{{ props.label }}</Label>
        <div ref="field" class="relative">
            <Search
                class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                :id="props.id"
                v-model="text"
                type="search"
                class="ps-9 pe-10 [&::-webkit-search-cancel-button]:appearance-none"
                :placeholder="props.placeholder"
                autocomplete="off"
                :data-test="props.dataTest ?? undefined"
                @keydown.escape="clear"
            />
            <button
                v-if="text !== ''"
                type="button"
                class="absolute end-1.5 top-1/2 flex size-7 -translate-y-1/2 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                :aria-label="t('Clear the search')"
                data-test="clear-search"
                @click="clear"
            >
                <X class="size-4" aria-hidden="true" />
            </button>
        </div>
    </div>
</template>
