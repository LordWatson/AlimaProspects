<script setup lang="ts">
import { AtSign, Check, Lock, MessageSquare, Phone } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import type {
    OutreachChannel,
    OutreachChannelOption,
    OutreachDecisions,
} from '@/types';

/*
 * The channel a message is drafted for — and, where a channel is closed, why.
 *
 * A **refused channel stays on screen, disabled, with its reason beside it**. Hiding it would be
 * the easier build and the worse screen: a member who can't find email assumes the product is
 * broken, where a member who sees "Get consent first — approach via LinkedIn or a referral" has
 * learned the rule and knows what to do instead. That sentence is the restriction doing its job
 * (docs/modules/outreach.md), not an error state.
 *
 * Brand: the selected channel is the Acid Lime pill the sidebar uses for its active item — lime is
 * the shell's hero accent, decorative. A closed channel is muted, never Signal Orange, which is
 * decorative only and never a status (brand-tokens.md §2.1).
 */
const props = defineProps<{
    modelValue: OutreachChannel;
    channels: OutreachChannelOption[];
    decisions: OutreachDecisions;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: OutreachChannel): void;
}>();

const { t } = useI18n();

// No brand glyphs in this icon set, and none wanted: the icon should say what the member does,
// and a LinkedIn message is a message they send themselves.
const icons = {
    email: AtSign,
    linkedin: MessageSquare,
    call: Phone,
} as const;

const rows = computed(() =>
    props.channels.map((channel) => {
        const decision = props.decisions[channel.value];

        return {
            ...channel,
            icon: icons[channel.value],
            allowed: decision?.allowed ?? true,
            // Every rule that refused, not just the first, so a member isn't sent round a loop
            // fixing one problem at a time.
            reasons: decision?.reasons ?? [],
        };
    }),
);
</script>

<template>
    <fieldset class="space-y-3">
        <legend
            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
        >
            {{ t('Channel') }}
        </legend>

        <div class="flex flex-wrap gap-2">
            <Button
                v-for="row in rows"
                :key="row.value"
                type="button"
                size="sm"
                :variant="modelValue === row.value ? 'lime' : 'outline'"
                :disabled="!row.allowed"
                :aria-pressed="modelValue === row.value"
                :aria-describedby="
                    row.allowed ? undefined : `blocked-${row.value}`
                "
                :data-test="`channel-${row.value}`"
                @click="emit('update:modelValue', row.value)"
            >
                <!-- A tick as well as the fill: lime against the outline variant is close
                     enough in luminance that colour alone cannot carry the selection
                     (WCAG 1.4.1). -->
                <component
                    :is="
                        !row.allowed
                            ? Lock
                            : modelValue === row.value
                              ? Check
                              : row.icon
                    "
                    class="size-4"
                />
                {{ t(row.label) }}
            </Button>
        </div>

        <!-- What the selected channel does, so a member knows whether pressing Send sends. -->
        <p
            v-for="row in rows.filter((r) => r.value === modelValue)"
            :key="`hint-${row.value}`"
            class="text-xs text-muted-foreground"
        >
            {{ t(row.description) }}
        </p>

        <!-- Why a channel is closed. Kept for every closed channel, not only the selected one. -->
        <ul class="space-y-2">
            <li
                v-for="row in rows.filter((r) => !r.allowed)"
                :key="`blocked-${row.value}`"
                :id="`blocked-${row.value}`"
                class="rounded-xl border border-border bg-muted/40 p-3 text-xs"
                :data-test="`blocked-${row.value}`"
            >
                <p class="font-semibold text-foreground">
                    {{ t(row.label) }} — {{ t(row.reasons[0]?.label ?? '') }}
                </p>
                <p
                    v-for="reason in row.reasons"
                    :key="reason.value"
                    class="mt-1 text-muted-foreground"
                >
                    {{ t(reason.guidance) }}
                </p>
            </li>
        </ul>
    </fieldset>
</template>
