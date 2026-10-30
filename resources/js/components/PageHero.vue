<script setup lang="ts">
import { computed } from 'vue';
import PandaPeek from '@/components/PandaPeek.vue';
import { useFormat } from '@/composables/useFormat';

/*
 * The Alima page hero — the Midnight Ink card that opens every main screen.
 *
 * The composition is fixed, and matches the reference screens in `resources/screenshots/`
 * (`Dashboard1.png`, `Clients.png`): a tracked mono eyebrow with the screen's sidebar number and a
 * right-hand meta, a hairline rule, one big statement whose tail is Acid Lime, the one rounded
 * Signal Orange rule, a lead paragraph, the screen's actions, an optional headline figure on the
 * right — and the lilac peek set into the bottom end corner.
 *
 * **The peek does not vary.** Same mark, same Soft Lilac, same corner, same crop, on every screen.
 * An earlier version varied the colour and position per screen; it made the app look like eight
 * different products. One peek, one colour, one placement (brand-tokens.md §5) — the screen's
 * identity comes from its number, its statement and its figure, not from moving the panda.
 *
 * Brand (brand-tokens.md §1.2, §3, §5):
 *  - Ink panel, Cloud Cream type, Acid Lime on the statement's tail and the headline figure;
 *    Signal Orange appears once, as the rule — decorative, never a status.
 *  - The peek is scaled up and cropped by the card, bleeding the bottom and end edges, both eyes in
 *    frame. The artwork is never altered: §4's "Never" list includes turning the pupils.
 *  - The eyebrow is tracked CAPS in Latin; `tracking-eyebrow` resolves to 0 under RTL, where
 *    Arabic is never letter-spaced.
 */
const props = withDefaults(
    defineProps<{
        /** The screen's number in the sidebar ("01"…"08"), shown in the eyebrow. */
        index?: string | null;
        /** Eyebrow label — the section name. Already translated by the page. */
        eyebrow: string;
        /**
         * The statement, already translated, with a `|` marking where the Acid Lime tail begins —
         * `t('Find your next|leads.')`.
         *
         * **One** translation key, not two, because where the line breaks is a typographic choice
         * that belongs to each language: two keys would force Arabic to accept English word order.
         * A translator moves the `|`; a string with no `|` is simply set in Cloud Cream.
         */
        title: string;
        /**
         * A Lime tail that is **data, not copy** — the dashboard's first name. Already a finished
         * string, so it is not translated. Wins over the `|` in `title`.
         */
        accent?: string | null;
        /** Lead paragraph, already translated. */
        description?: string | null;
        /**
         * Right-hand mono meta beside the eyebrow. Defaults to today's long date; pass a string to
         * show something screen-specific, or `null` for nothing.
         */
        meta?: string | null;
        /**
         * The one figure that matters on this screen, set large in Lime on the right — the
         * reference's "NEEDS ATTENTION / 14 / Decisions waiting on you". Counts are zero-padded to
         * two digits, as in the reference. Omit it and the statement simply runs wider.
         */
        stat?: {
            /** Tracked mono label above the figure. Already translated. */
            label: string;
            value: number;
            /** One line under the figure. Already translated. */
            caption: string;
        } | null;
        /**
         * The cropped panda. On by default — every hero carries one. Pass `:peek="false"` only
         * where the card sits beside another panda, since the brand allows one per layout.
         */
        peek?: boolean;
        /** Tighter padding and type for secondary screens (a detail page, a wizard). */
        size?: 'default' | 'compact';
    }>(),
    {
        index: null,
        accent: null,
        description: null,
        meta: undefined,
        stat: null,
        peek: true,
        size: 'default',
    },
);

const { date, number } = useFormat();

const today = computed(() =>
    date(new Date(), {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
    }).toUpperCase(),
);

// `undefined` means "use the date", `null` means "no meta at all" — a distinction a single
// optional string cannot make, and screens need both.
const metaText = computed(() =>
    props.meta === undefined ? today.value : props.meta,
);

const isCompact = computed(() => props.size === 'compact');

// The statement arrives translated, so each language has already picked its own break point.
const statement = computed(() => {
    const [head, ...tail] = props.title.split('|');

    return {
        head: head ?? '',
        accent: props.accent ?? (tail.length > 0 ? tail.join('|') : null),
    };
});

// "00", not "0" — the reference pads a single digit so the figure keeps its weight when a team is
// starting from nothing. Past 99 it is just the number, and the locale formats it.
const statValue = computed(() => {
    const value = props.stat?.value ?? 0;

    return value < 10 && value >= 0 ? `0${value}` : number(value);
});
</script>

<template>
    <section
        class="relative isolate overflow-hidden rounded-3xl bg-ink text-cream"
        :class="
            isCompact
                ? 'p-5 sm:p-7 lg:p-9'
                : 'p-6 sm:p-9 lg:min-h-80 lg:p-12 lg:pb-14'
        "
    >
        <!--
          The peek: scaled up, set into the bottom end corner and cropped by the card on two edges,
          with both eyes in frame (brand-tokens.md §5). `z-0` under the content, which is `z-10`, so
          it can never come between a reader and the copy. Logical offsets, so the layout mirrors
          under RTL while the mark itself never does.
          `aspect` is the master artwork's own viewBox ratio (383.24 × 277.89), not a design token.
          The bottom offsets hold the crop at about three quarters of the face at every width —
          ears and eyes in frame, the smile below the card edge — matching the reference screens.
        -->
        <PandaPeek
            v-if="peek"
            tone="text-lilac"
            class="absolute -end-3 -bottom-8 z-0 aspect-[383/278] w-48 sm:-bottom-11 sm:w-64 lg:-bottom-14 lg:w-80 xl:-bottom-16 xl:w-[22rem]"
        />

        <!-- Eyebrow: number + section, and the date (or screen meta) -->
        <div
            class="relative z-10 flex items-center justify-between gap-4 font-mono text-xs tracking-eyebrow text-cream/60 uppercase rtl:tracking-normal"
        >
            <span class="flex min-w-0 items-center gap-2">
                <span
                    class="size-2.5 shrink-0 rounded-sm bg-lime"
                    aria-hidden="true"
                />
                <span class="truncate"
                    ><template v-if="index">{{ index }} — </template
                    >{{ eyebrow }}</span
                >
            </span>
            <span v-if="metaText" class="shrink-0 tabular-nums">{{
                metaText
            }}</span>
        </div>
        <div
            class="relative z-10 mt-4 h-px w-full bg-cream/15"
            aria-hidden="true"
        />

        <div
            class="relative z-10 grid gap-8"
            :class="[
                isCompact ? 'mt-6' : 'mt-8',
                stat ? 'lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start' : '',
            ]"
        >
            <div>
                <!-- Tight Latin leading (0.95–1.1, brand-tokens.md §3); Tajawal's taller
                     ascenders and descenders need the room back under RTL. -->
                <h1
                    class="leading-[0.98] font-bold tracking-tight rtl:leading-tight"
                    :class="
                        isCompact
                            ? 'text-3xl sm:text-4xl lg:text-5xl'
                            : 'text-4xl sm:text-5xl lg:text-6xl xl:text-7xl'
                    "
                >
                    {{ statement.head
                    }}<template v-if="statement.head && statement.accent"
                        ><br /></template
                    ><span v-if="statement.accent" class="text-lime">{{
                        statement.accent
                    }}</span>
                </h1>

                <!-- The one rounded Signal Orange rule per layout (decorative). -->
                <div
                    class="mt-6 h-1 w-14 rounded-full bg-orange"
                    aria-hidden="true"
                />

                <p
                    v-if="description"
                    class="max-w-xl text-cream/70"
                    :class="isCompact ? 'mt-5' : 'mt-7'"
                >
                    {{ description }}
                </p>

                <!-- Screen actions. `variant="lime"` for the primary, `variant="hero"` for the
                     outline secondaries, so both read on Ink. -->
                <div
                    v-if="$slots.actions"
                    class="flex flex-wrap items-center gap-3"
                    :class="isCompact ? 'mt-6' : 'mt-8'"
                >
                    <slot name="actions" />
                </div>

                <slot />
            </div>

            <!-- The headline figure, set against the end edge above the peek. -->
            <div v-if="stat" class="lg:text-end">
                <p
                    class="font-mono text-xs tracking-eyebrow text-cream/60 uppercase rtl:tracking-normal"
                >
                    {{ stat.label }}
                </p>
                <p
                    class="mt-3 text-6xl leading-none font-bold tracking-tight text-lime tabular-nums lg:text-7xl"
                >
                    {{ statValue }}
                </p>
                <p class="mt-3 text-sm text-cream/70">{{ stat.caption }}</p>
            </div>
        </div>
    </section>
</template>
