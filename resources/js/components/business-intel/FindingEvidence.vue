<script setup lang="ts">
import { ExternalLink } from '@lucide/vue';
import { useI18n } from '@/composables/useI18n';
import type { BackgroundCheckEvidence } from '@/types';

/**
 * Why Alima believes a finding: the words it read, and the page they are on.
 *
 * A finding nobody can check is a finding nobody should accept, so the quote is shown as a quote
 * and the page is a link. The server has already confirmed each quote really is on its page; an
 * empty list therefore means the finding was reasoned out rather than read, and says so.
 */
const props = defineProps<{
    /** Undefined on a report written before findings carried evidence: nothing is shown. */
    items: BackgroundCheckEvidence[] | undefined;
}>();

const { t } = useI18n();

/** In words, not a percentage: nobody can act on the difference between 82 and 87. */
function strength(confidence: number): string {
    if (confidence >= 80) {
        return t('Stated on your site');
    }

    return confidence >= 50
        ? t('Suggested by your site')
        : t('A weaker hint on your site');
}

function pageLabel(url: string | undefined): string {
    if (!url) {
        return t('Your site');
    }

    try {
        const parsed = new URL(url);

        return parsed.pathname === '/' ? parsed.host : parsed.pathname;
    } catch {
        return url;
    }
}
</script>

<template>
    <div
        v-if="props.items !== undefined"
        class="border-t border-border pt-3"
        data-test="finding-evidence"
    >
        <p v-if="!props.items.length" class="text-xs text-muted-foreground">
            {{
                t(
                    'Alima worked this out from what you do. Nothing on your site says it outright.',
                )
            }}
        </p>
        <ul v-else class="flex flex-col gap-3">
            <li
                v-for="(item, index) in props.items"
                :key="index"
                class="flex flex-col gap-1"
            >
                <blockquote
                    class="border-s-2 border-border ps-3 text-sm text-foreground"
                    dir="auto"
                >
                    “{{ item.quote }}”
                </blockquote>
                <p
                    class="flex flex-wrap items-center gap-x-2 gap-y-1 ps-3 text-xs text-muted-foreground"
                >
                    <span>{{ strength(item.confidence) }}</span>
                    <a
                        v-if="item.url"
                        :href="item.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 rounded-sm font-mono underline underline-offset-2 outline-none focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                    >
                        <bdi dir="ltr">{{ pageLabel(item.url) }}</bdi>
                        <ExternalLink class="size-3" aria-hidden="true" />
                        <span class="sr-only">{{
                            t('(opens in a new tab)')
                        }}</span>
                    </a>
                </p>
            </li>
        </ul>
    </div>
</template>
