<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/composables/useI18n';

/*
 * A simple add-to-list (chip) input for repeated short strings — locations, products/services and
 * example customers. Type and press Enter (or the add button) to add; each entry shows as a pill
 * with a remove control. Logical properties + brand tokens keep it correct in LTR and RTL.
 */
const props = defineProps<{
    modelValue: string[];
    placeholder?: string;
    inputId?: string;
    // Names the list of added items (the text input is named by its external <Label for>).
    listLabel?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string[]): void;
}>();

const { t } = useI18n();
const draft = ref('');

function add(): void {
    const value = draft.value.trim();
    if (value === '' || props.modelValue.includes(value)) {
        draft.value = '';
        return;
    }
    emit('update:modelValue', [...props.modelValue, value]);
    draft.value = '';
}

function remove(index: number): void {
    emit(
        'update:modelValue',
        props.modelValue.filter((_, i) => i !== index),
    );
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex gap-2">
            <Input
                :id="inputId"
                v-model="draft"
                :placeholder="placeholder"
                autocomplete="off"
                @keydown.enter.prevent="add"
            />
            <Button
                type="button"
                variant="outline"
                size="icon"
                :aria-label="
                    listLabel
                        ? t('Add to :list', { list: listLabel })
                        : t('Add')
                "
                @click="add"
            >
                <Plus class="size-4" />
            </Button>
        </div>

        <ul
            v-if="modelValue.length"
            class="flex flex-wrap gap-2"
            :aria-label="listLabel"
        >
            <li
                v-for="(item, index) in modelValue"
                :key="`${item}-${index}`"
                class="inline-flex items-center gap-1.5 rounded-full bg-brand/10 py-1 ps-3 pe-1 text-sm text-brand"
            >
                <span>{{ item }}</span>
                <button
                    type="button"
                    class="flex size-6 items-center justify-center rounded-full text-brand/70 transition-colors hover:bg-brand/15 hover:text-brand focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="t('Remove :item', { item })"
                    @click="remove(index)"
                >
                    <X class="size-3.5" />
                </button>
            </li>
        </ul>
    </div>
</template>
