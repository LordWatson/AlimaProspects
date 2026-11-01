<script setup lang="ts">
import type { Component } from 'vue';
import { useI18n } from '@/composables/useI18n';

/*
 * One thing the lead screen needs to tell a member: a compliance flag, or why the fields are thin.
 *
 * It follows the status-panel rules the admin panel wrote down and the Phase 19c review measured
 * (brand-tokens.md §7f.2–3, §7g.2): a 12% tint of the status colour with a 30% border, the colour
 * kept to the **icon and the border**, and the words in `text-foreground` — because a status colour
 * over a tint of itself does not clear AA at this size, and `status.info` is the worst of them at
 * about 4.4:1.
 *
 * The level is also said in a visually hidden word, because `warning` and `info` otherwise differ
 * only by hue, and colour alone is not a distinction (WCAG 1.4.1).
 *
 * The body is `text-foreground/80`, not `text-muted-foreground`: muted is 4.8:1 on Cloud Cream but
 * about **4.4:1 on these tints**, under AA at 14px. §7f.3's rule is that the words on a tint are
 * the foreground colour, and that applies to the sentence as well as the title.
 */
const props = defineProps<{
    notice: {
        key: string;
        tone: 'warning' | 'info';
        icon: Component;
        /** Already translated. */
        title: string;
        /** Already translated. */
        body: string;
    };
}>();

const { t } = useI18n();
</script>

<template>
    <div
        class="flex items-start gap-3 rounded-xl border p-4"
        :class="
            props.notice.tone === 'warning'
                ? 'border-warning/30 bg-warning/12'
                : 'border-info/30 bg-info/12'
        "
        role="note"
        :data-test="props.notice.key"
    >
        <component
            :is="props.notice.icon"
            class="mt-0.5 size-5 shrink-0"
            :class="
                props.notice.tone === 'warning' ? 'text-warning' : 'text-info'
            "
            aria-hidden="true"
        />
        <div class="space-y-0.5">
            <p class="text-sm font-semibold text-foreground rtl:font-bold">
                <span class="sr-only"
                    >{{
                        props.notice.tone === 'warning'
                            ? t('Heads-up')
                            : t('Notice')
                    }}:
                </span>
                {{ props.notice.title }}
            </p>
            <p class="text-sm text-foreground/80">
                {{ props.notice.body }}
            </p>
        </div>
    </div>
</template>
