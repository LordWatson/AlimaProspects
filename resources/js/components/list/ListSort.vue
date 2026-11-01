<script setup lang="ts">
import { ArrowDown, ArrowUp } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { useI18n } from '@/composables/useI18n';
import type { LeadFilterOption } from '@/types';

/*
 * The ordering control: what to order by, and which way round.
 *
 * It stays even though the column headers are sortable now, and on purpose — a header can only
 * offer an ordering that has a column, and "newest first" has none. The two are kept in step
 * because both drive the same two query parameters.
 *
 * Changing *what* to order by resets the direction to that ordering's own natural one (newest
 * means newest first; company name means A first), which is what the server's
 * `LeadSort::defaultDirection()` already believed and the screen had to be told.
 */
const props = withDefaults(
    defineProps<{
        /** The orderings on offer; `label` is a translation key. */
        options: LeadFilterOption[];
        value: string;
        direction: 'asc' | 'desc';
        label?: string | null;
        id?: string;
    }>(),
    { label: null, id: 'list-sort' },
);

const emit = defineEmits<{
    sort: [key: string];
    direction: [direction: 'asc' | 'desc'];
}>();

const { t } = useI18n();

const directionLabel = computed(() =>
    props.direction === 'asc'
        ? t('Lowest first — switch to highest first')
        : t('Highest first — switch to lowest first'),
);
</script>

<template>
    <div class="grid gap-2">
        <Label :for="props.id">{{ props.label ?? t('Order by') }}</Label>
        <div class="flex gap-2">
            <NativeSelect
                :id="props.id"
                :model-value="props.value"
                class="w-44"
                data-test="list-sort"
                @update:model-value="emit('sort', String($event))"
            >
                <option
                    v-for="option in props.options"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ t(option.label) }}
                </option>
            </NativeSelect>
            <Button
                variant="outline"
                size="icon"
                type="button"
                :aria-label="directionLabel"
                :title="directionLabel"
                data-test="list-direction"
                @click="
                    emit(
                        'direction',
                        props.direction === 'asc' ? 'desc' : 'asc',
                    )
                "
            >
                <!-- Vertical arrows are not mirrored under RTL. -->
                <component
                    :is="props.direction === 'asc' ? ArrowUp : ArrowDown"
                    class="size-4"
                    aria-hidden="true"
                />
            </Button>
        </div>
    </div>
</template>
