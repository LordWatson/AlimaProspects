<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/composables/useAppearance';
import { useI18n } from '@/composables/useI18n';
import type { Appearance } from '@/types';

/* Compact light/dark/system switch for the app top bar. */
const { appearance, updateAppearance } = useAppearance();
const { t } = useI18n();

const options = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;

const ActiveIcon = computed(() => {
    if (appearance.value === 'light') return Sun;
    if (appearance.value === 'dark') return Moon;
    return Monitor;
});
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                :aria-label="t('Appearance')"
                data-test="appearance-toggle"
            >
                <component :is="ActiveIcon" class="size-5" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="min-w-36">
            <DropdownMenuItem
                v-for="option in options"
                :key="option.value"
                class="gap-2"
                :data-active="appearance === option.value"
                @select="updateAppearance(option.value as Appearance)"
            >
                <component :is="option.Icon" class="size-4" />
                <span>{{ t(option.label) }}</span>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
