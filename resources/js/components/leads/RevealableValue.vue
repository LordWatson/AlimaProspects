<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Eye, Mail, MessageCircle, Phone } from '@lucide/vue';
import { computed } from 'vue';
import CopyButton from '@/components/CopyButton.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';

/*
 * One contact detail: a link to use it, or a masked form and a button to buy it (PL3).
 *
 * The component exists because email and phone need identical behaviour and the difference between
 * "show it" and "sell it" is one boolean — and because that boolean is the one thing a screen must
 * not get wrong. A masked value rendered as a `mailto:` is a paid reveal given away in an
 * attribute.
 *
 * Three decisions worth knowing:
 *
 * - **No link, no copy button and no deep link while masked.** The value is `j***@acme.com`; there
 *   is nothing there to use, so offering to copy it or mail it would be offering a stub. The
 *   server sends one already-masked field (ADR 0029), so this component never holds the real value
 *   before the reveal.
 * - **The button says what it costs.** Not a figure — PL4 owns the allowance and the number — but
 *   it says "uses 1 credit", because a button that spends money without saying so is a button a
 *   member resents the second time.
 * - **The messaging link is a mobile's only.** A `wa.me` link on a landline opens a chat that can
 *   never be delivered, which is worse than no link: it looks like it worked.
 */
const props = withDefaults(
    defineProps<{
        /** Already masked by the server where a reveal is owed. Null when there is nothing. */
        value: string | null;
        kind: 'email' | 'phone';
        /** Whether this value has to be bought before it can be used. */
        masked: boolean;
        /** Where to POST the reveal. */
        revealUrl: string;
        /** Whether this member may spend. A Read-only member sees the mask and no button. */
        canReveal: boolean;
        /** Only for a mobile: offer a WhatsApp link once revealed. */
        messaging?: boolean;
    }>(),
    { messaging: false },
);

const { t } = useI18n();

const form = useForm({});

const icon = computed(() => (props.kind === 'email' ? Mail : Phone));

/** `mailto:` or `tel:`, and only ever built from a value that is really there. */
const href = computed(() =>
    props.kind === 'email' ? `mailto:${props.value}` : `tel:${props.value}`,
);

/**
 * WhatsApp wants the number without a `+` or any punctuation. Built from the E.164 form the server
 * sends, so a number it could not normalise simply has no link.
 */
const messagingHref = computed(
    () => `https://wa.me/${(props.value ?? '').replace(/\D+/g, '')}`,
);

function reveal(): void {
    form.post(props.revealUrl, { preserveScroll: true });
}
</script>

<template>
    <span v-if="value" class="flex min-w-0 items-center gap-1">
        <!-- Masked: the value as a stub, and the way to buy it. No link, nothing to copy. -->
        <template v-if="masked">
            <span
                class="inline-flex min-w-0 items-center gap-1.5 text-muted-foreground"
                dir="ltr"
            >
                <component
                    :is="icon"
                    class="size-3.5 shrink-0"
                    aria-hidden="true"
                />
                <span class="truncate">{{ value }}</span>
            </span>

            <Button
                v-if="canReveal"
                variant="outline"
                size="sm"
                class="h-6 gap-1 px-2 text-xs"
                :disabled="form.processing"
                :data-test="`reveal-${kind}`"
                @click="reveal"
            >
                <Eye class="size-3" aria-hidden="true" />
                <!-- Says that it costs, without inventing the figure: PL4 owns the allowance. -->
                {{ t('Show (1 credit)') }}
            </Button>
        </template>

        <!-- Revealed, or never masked: a real value, usable. -->
        <template v-else>
            <a
                :href="href"
                class="inline-flex min-w-0 items-center gap-1.5 rounded text-brand hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                dir="ltr"
            >
                <component
                    :is="icon"
                    class="size-3.5 shrink-0"
                    aria-hidden="true"
                />
                <span class="truncate">{{ value }}</span>
            </a>
            <CopyButton
                :value="value"
                :label="
                    kind === 'email' ? t('email address') : t('phone number')
                "
            />
            <a
                v-if="messaging"
                :href="messagingHref"
                target="_blank"
                rel="noopener noreferrer"
                class="rounded p-1 text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                :aria-label="t('Message on WhatsApp')"
            >
                <MessageCircle class="size-3.5" aria-hidden="true" />
            </a>
        </template>
    </span>
</template>
