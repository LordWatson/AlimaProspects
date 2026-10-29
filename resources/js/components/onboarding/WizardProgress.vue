<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from '@/composables/useI18n';
import type { OnboardingStepMeta } from '@/types';

/*
 * The wizard step rail: a vertical list on desktop, a compact progress bar on mobile. Completed
 * steps show a snout-teal tick and are clickable to jump back; the active step is highlighted;
 * upcoming steps are muted and locked. Brand: Deep Teal structure, Cloud Cream canvas.
 */
const props = defineProps<{
    steps: OnboardingStepMeta[];
    currentIndex: number;
    completedKeys: string[];
}>();

const emit = defineEmits<{
    (e: 'go', index: number): void;
}>();

const { t } = useI18n();

const total = computed(() => props.steps.length);
const percent = computed(() =>
    total.value <= 1
        ? 100
        : Math.round((props.currentIndex / (total.value - 1)) * 100),
);

function isCompleted(step: OnboardingStepMeta): boolean {
    return props.completedKeys.includes(step.key);
}

function isReachable(step: OnboardingStepMeta, index: number): boolean {
    return index <= props.currentIndex || isCompleted(step);
}

function select(step: OnboardingStepMeta, index: number): void {
    if (isReachable(step, index) && index !== props.currentIndex) {
        emit('go', index);
    }
}
</script>

<template>
    <!-- Mobile: compact bar -->
    <div class="lg:hidden">
        <p class="mb-2 font-mono text-xs tracking-eyebrow text-brand uppercase">
            {{
                t('Step :n of :total', {
                    n: currentIndex + 1,
                    total,
                })
            }}
            — {{ t(steps[currentIndex]?.label ?? '') }}
        </p>
        <div
            class="h-1.5 w-full overflow-hidden rounded-full bg-brand/15"
            role="progressbar"
            :aria-valuenow="currentIndex + 1"
            aria-valuemin="1"
            :aria-valuemax="total"
        >
            <div
                class="duration-base h-full rounded-full bg-brand transition-[inline-size] ease-brand"
                :style="{ inlineSize: `${percent}%` }"
            />
        </div>
    </div>

    <!-- Desktop: vertical rail -->
    <ol class="hidden gap-1 lg:grid" :aria-label="t('Setup steps')">
        <li v-for="(step, index) in steps" :key="step.key">
            <button
                type="button"
                class="group flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-start transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                :class="[
                    index === currentIndex
                        ? 'bg-brand/10'
                        : isReachable(step, index)
                          ? 'hover:bg-brand/5'
                          : 'cursor-default',
                ]"
                :disabled="!isReachable(step, index)"
                :aria-current="index === currentIndex ? 'step' : undefined"
                @click="select(step, index)"
            >
                <span
                    class="flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-semibold transition-colors"
                    :class="[
                        isCompleted(step)
                            ? 'border-brand bg-brand text-brand-foreground'
                            : index === currentIndex
                              ? 'border-brand text-brand'
                              : 'border-border text-muted-foreground',
                    ]"
                >
                    <Check v-if="isCompleted(step)" class="size-4" />
                    <template v-else>{{ step.position }}</template>
                </span>
                <span
                    class="text-sm"
                    :class="
                        index === currentIndex
                            ? 'font-semibold text-foreground'
                            : 'text-muted-foreground'
                    "
                >
                    {{ t(step.label) }}
                </span>
            </button>
        </li>
    </ol>
</template>
