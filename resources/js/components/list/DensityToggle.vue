<script setup lang="ts">
import { Rows2, Rows3 } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useListDensity } from '@/composables/useListDensity';
import { useI18n } from '@/composables/useI18n';

/*
 * Comfortable or compact rows, for the whole product.
 *
 * Two people use a leads list completely differently: one opens it twice a day and wants it
 * readable, the other works it for an hour and wants forty rows on screen without scrolling.
 * There is no single right row height, so this is the member's choice and it is remembered
 * (`useListDensity` — localStorage, one setting across every list, like the appearance toggle).
 */
const { density, toggleDensity } = useListDensity();

const { t } = useI18n();

/**
 * The button's **name does not change** — only its pressed state does.
 *
 * A toggle whose label flips to describe the opposite action reads, together with
 * `aria-pressed`, as "Comfortable rows, pressed" while the rows are compact: the name and
 * the state contradict each other. So the name is the thing being toggled, and the
 * sentence about what the next click does lives in the tooltip, for the mouse.
 */
const hint = computed(() =>
    density.value === 'compact'
        ? t('Compact rows — switch to comfortable')
        : t('Comfortable rows — switch to compact'),
);
</script>

<template>
    <Button
        variant="outline"
        size="icon"
        type="button"
        :aria-label="t('Compact rows')"
        :title="hint"
        :aria-pressed="density === 'compact'"
        data-test="density-toggle"
        @click="toggleDensity"
    >
        <component
            :is="density === 'compact' ? Rows3 : Rows2"
            class="size-4"
            aria-hidden="true"
        />
    </Button>
</template>
