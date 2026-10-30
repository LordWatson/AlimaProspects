<script setup lang="ts">
import { useVModel } from '@vueuse/core';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/*
 * A plain `<select>`, styled to match `Input`.
 *
 * Deliberately **not** the reka-ui `Select` in `ui/select/`: that is a listbox built out of divs,
 * which is right for a rich filter but wrong for a short form field — it loses the native mobile
 * picker (the wheel on iOS, the dialog on Android) and the native form post. Several screens were
 * already hand-rolling this element with a copy of `Input`'s class string; this is that copy, once.
 *
 * `shape` keeps the `aria-invalid` and `disabled` states `Input` has, which the hand-rolled copies
 * had all quietly dropped.
 */
const props = defineProps<{
    modelValue?: string | number | null;
    defaultValue?: string | number | null;
    class?: HTMLAttributes['class'];
}>();

const emits = defineEmits<{
    (e: 'update:modelValue', payload: string | number | null): void;
}>();

const modelValue = useVModel(props, 'modelValue', emits, {
    passive: true,
    defaultValue: props.defaultValue,
});
</script>

<template>
    <select
        v-model="modelValue"
        data-slot="native-select"
        :class="
            cn(
                'border-input h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none dark:bg-input/30 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                'focus-visible:border-ring focus-visible:ring-ring focus-visible:ring-[3px]',
                'aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40',
                props.class,
            )
        "
    >
        <slot />
    </select>
</template>
