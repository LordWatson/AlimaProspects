<script setup lang="ts">
import { computed } from 'vue';

/*
 * One header for every panel screen (the 19d overhaul).
 *
 * **Why a component rather than a convention.** Every screen in the panel had written its own
 * `<header>`, and eighteen hand-rolled headers had drifted into eighteen different type scales,
 * spacings and eyebrow treatments — which is most of what "every page is boring" was describing.
 * A page with no hierarchy reads as a data dump whatever the data is.
 *
 * It is the tenant app's `PageHero` device, adapted rather than copied:
 *
 *  - **The eyebrow** is DM Mono at the tracked 12px floor (§7e.2 keeps the floor, §3 the face), and
 *    it names the *section* — "Platform · reporting" — so a screen says where it sits.
 *  - **The statement** takes a `|`, exactly as `PageHero` does: everything after it is set in the
 *    accent. One accent per layout (§1.2), and in the panel that accent is Deep Teal rather than
 *    Acid Lime, because lime is the tenant shell's hero and an operator must never mistake one
 *    surface for the other (ADR 0014).
 *  - **The lede** is the one sentence that tells an operator what this screen is for and what it
 *    costs — several of these screens read tenant data, which is audited, and that belongs on the
 *    page rather than in a runbook.
 *  - **`meta`** is the mono line on the right: UTC by default, because every timestamp in the panel
 *    is UTC and saying so once at the top is cheaper than labelling every cell (§7f).
 *
 * No panda and no logo: the rail carries the mark, and the brand allows one per layout (§5).
 */
const props = withDefaults(
    defineProps<{
        /** Mono eyebrow — the section this screen belongs to. */
        eyebrow: string;
        /**
         * The statement. A `|` marks where the Deep Teal tail begins; without one the whole line
         * is set in `foreground`.
         */
        title: string;
        /** One sentence: what this screen is for, and anything it costs to open. */
        lede?: string | null;
        /** Mono meta on the right. Defaults to the UTC reminder; pass `null` for nothing. */
        meta?: string | null;
        /** `01`…`09`, the editorial device the tenant rail uses. Omitted on most panel screens. */
        index?: string | null;
    }>(),
    { lede: null, meta: undefined, index: null },
);

const statement = computed(() => {
    const [head, ...tail] = props.title.split('|');

    return {
        head: head ?? '',
        accent: tail.length > 0 ? tail.join('|') : null,
    };
});

const metaText = computed(() =>
    props.meta === undefined ? 'All times UTC' : props.meta,
);
</script>

<template>
    <header class="flex flex-col gap-4">
        <div
            class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2"
        >
            <p
                class="flex items-baseline gap-2 font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
            >
                <span v-if="index" class="text-teal">{{ index }}</span>
                {{ eyebrow }}
            </p>

            <p
                v-if="metaText"
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase tabular-nums"
            >
                {{ metaText }}
            </p>
        </div>

        <div
            class="flex flex-wrap items-end justify-between gap-x-8 gap-y-4 border-b border-border pb-5"
        >
            <div class="max-w-2xl space-y-2">
                <h1
                    class="text-2xl leading-tight font-semibold tracking-tight text-foreground"
                >
                    <!-- The line break goes **here**, not in the copy. The first version
                         concatenated the halves with nothing between them, so ten screens read
                         "Onboarda business." — `PageHero` has always put a `<br>` at the split and
                         this is the same device (brand review). -->
                    {{ statement.head
                    }}<template v-if="statement.head && statement.accent"
                        ><br /></template
                    ><span v-if="statement.accent" class="text-teal">{{
                        statement.accent
                    }}</span>
                </h1>
                <p v-if="lede" class="text-sm text-muted-foreground">
                    {{ lede }}
                </p>
            </div>

            <!-- Where a screen's own buttons go, so they sit on the rule rather than floating
                 above the first card. -->
            <div
                v-if="$slots.actions"
                class="flex flex-wrap items-center gap-2"
            >
                <slot name="actions" />
            </div>
        </div>
    </header>
</template>
