<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CornerDownLeft, Search } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import {
    Dialog,
    DialogDescription,
    DialogScrollContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { useAdminNav } from '@/composables/useAdminNav';

/*
 * Jump to anything, with ⌘K (the 19d overhaul).
 *
 * **The panel has fifty-five destinations.** Forty-four of them are record browsers that an
 * operator opens once a fortnight, and the way you reach one today is to read a rail of forty-four
 * links looking for the word you already have in your head. That is the navigation complaint: not
 * that the links are wrong, but that finding one is a scan rather than a search.
 *
 * So: type three letters, press Enter. It matches the label, the group and a handful of
 * hand-written keywords per destination — "tenant" finds Teams, "dsar" finds Compliance, "kill
 * switch" finds Platform settings — because an operator thinks in the words of the problem, not in
 * our table names.
 *
 * Keyboard-first by construction: ⌘K/Ctrl-K opens, typing filters, ↑/↓ move, Enter navigates,
 * Escape closes. The list is `role="listbox"` with `aria-activedescendant`, so the highlighted row
 * is announced rather than only coloured (WCAG 4.1.2) — and the mouse works for everybody who
 * would rather point.
 */
const open = ref(false);
const query = ref('');
const active = ref(0);
const input = ref<HTMLInputElement | null>(null);

const { all } = useAdminNav();

const results = computed(() => {
    const needle = query.value.trim().toLowerCase();

    if (needle === '') {
        return all.value;
    }

    // Every word has to appear somewhere in the haystack, so "team bill" finds the team billing
    // screen without the order mattering.
    const words = needle.split(/\s+/);

    return all.value.filter((destination) => {
        const haystack = [
            destination.label,
            destination.group,
            destination.keywords ?? '',
        ]
            .join(' ')
            .toLowerCase();

        return words.every((word) => haystack.includes(word));
    });
});

/** The results in their groups, so a long list reads as a map rather than a column. */
const grouped = computed(() => {
    const sections = new Map<string, typeof results.value>();

    for (const destination of results.value) {
        const bucket = sections.get(destination.group) ?? [];

        bucket.push(destination);
        sections.set(destination.group, bucket);
    }

    return [...sections.entries()].map(([label, destinations]) => ({
        label,
        destinations,
    }));
});

/** The flat index of the highlighted row, which is what ↑/↓ and Enter work on. */
const flat = computed(() =>
    grouped.value.flatMap((group) => group.destinations),
);

watch(results, () => {
    active.value = 0;
});

function show(): void {
    open.value = true;
    query.value = '';
    active.value = 0;

    void nextTick(() => input.value?.focus());
}

function go(index: number): void {
    const destination = flat.value[index];

    if (destination === undefined) {
        return;
    }

    open.value = false;
    router.visit(destination.href);
}

function move(delta: number): void {
    const count = flat.value.length;

    if (count === 0) {
        return;
    }

    // Wraps, because a list of fifty-five is quicker to reach from the bottom sometimes.
    active.value = (active.value + delta + count) % count;

    // And scroll it into view: the highlight walked off the bottom of a `max-h-[60vh]` list
    // otherwise, which is a keyboard path that only works for the first eight rows.
    void nextTick(() => {
        document
            .getElementById(`admin-palette-${active.value}`)
            ?.scrollIntoView({ block: 'nearest' });
    });
}

function onKeydown(event: KeyboardEvent): void {
    const isPaletteKey =
        (event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k';

    if (isPaletteKey) {
        event.preventDefault();

        if (open.value) {
            open.value = false;
        } else {
            show();
        }
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

defineExpose({ show });
</script>

<template>
    <Dialog :open="open" @update:open="(value: boolean) => (open = value)">
        <DialogScrollContent
            class="top-[12vh] max-w-xl translate-y-0 gap-0 p-0"
            data-test="admin-command-palette"
        >
            <DialogTitle class="sr-only">Go to</DialogTitle>
            <DialogDescription class="sr-only">
                Search every screen in the panel. Use the arrow keys to choose
                one and Enter to open it.
            </DialogDescription>

            <!-- The ring is on the row, not the input: the input fills it, and an
                 `outline-none` field with no visible indicator fails 2.4.7 (brand review). -->
            <div
                class="flex items-center gap-3 border-b border-border px-4 py-3 focus-within:ring-[3px] focus-within:ring-ring focus-within:ring-inset"
            >
                <Search
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <input
                    ref="input"
                    v-model="query"
                    type="text"
                    role="combobox"
                    aria-label="Go to a screen"
                    aria-autocomplete="list"
                    :aria-expanded="flat.length > 0"
                    aria-controls="admin-palette-results"
                    :aria-activedescendant="
                        flat.length > 0 ? `admin-palette-${active}` : undefined
                    "
                    placeholder="Go to… (teams, dsar, failed jobs)"
                    class="w-full bg-transparent text-base text-foreground outline-none placeholder:text-muted-foreground"
                    autocomplete="off"
                    spellcheck="false"
                    @keydown.down.prevent="move(1)"
                    @keydown.up.prevent="move(-1)"
                    @keydown.enter.prevent="go(active)"
                />
                <kbd
                    class="hidden shrink-0 rounded border border-border px-1.5 py-0.5 font-mono text-xs text-muted-foreground sm:block"
                >
                    esc
                </kbd>
            </div>

            <div
                id="admin-palette-results"
                role="listbox"
                aria-label="Panel screens"
                class="max-h-[60vh] overflow-y-auto p-2"
            >
                <p
                    v-if="flat.length === 0"
                    class="px-3 py-6 text-center text-sm text-muted-foreground"
                >
                    Nothing matches “{{ query }}”.
                </p>

                <!-- Each group is a `role="group"` named by its own label, so the listbox
                     reads as a map rather than fifty-five peers (brand review). -->
                <div
                    v-for="group in grouped"
                    :key="group.label"
                    role="group"
                    :aria-labelledby="`admin-palette-group-${group.label}`"
                    class="mb-1 last:mb-0"
                >
                    <p
                        :id="`admin-palette-group-${group.label}`"
                        class="px-3 py-1.5 font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                    >
                        {{ group.label }}
                    </p>

                    <button
                        v-for="destination in group.destinations"
                        :id="`admin-palette-${flat.indexOf(destination)}`"
                        :key="destination.href"
                        type="button"
                        role="option"
                        tabindex="-1"
                        :aria-selected="flat.indexOf(destination) === active"
                        class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-start text-sm transition-colors"
                        :class="
                            flat.indexOf(destination) === active
                                ? 'bg-teal text-cream'
                                : 'text-foreground hover:bg-accent'
                        "
                        @click="go(flat.indexOf(destination))"
                        @mousemove="active = flat.indexOf(destination)"
                    >
                        <component
                            :is="destination.icon"
                            class="size-4 shrink-0 opacity-70"
                            aria-hidden="true"
                        />
                        <span class="flex-1 truncate">{{
                            destination.label
                        }}</span>
                        <CornerDownLeft
                            v-if="flat.indexOf(destination) === active"
                            class="size-3.5 shrink-0 opacity-70"
                            aria-hidden="true"
                        />
                    </button>
                </div>
            </div>
        </DialogScrollContent>
    </Dialog>
</template>
