<script setup lang="ts">
import { Check, Languages } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useI18n } from '@/composables/useI18n';

/*
 * Language switcher (EN / AR + RTL, ADR 0007). Shows each locale in its own
 * script; the active one is ticked. Switching persists via cookie and flips the
 * document direction on the next render.
 */
const { locale, available, setLocale, t } = useI18n();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                :aria-label="t('Language')"
                data-test="locale-toggle"
            >
                <Languages class="size-5" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="min-w-44">
            <DropdownMenuLabel class="text-xs text-muted-foreground">
                {{ t('Language') }}
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                v-for="option in available"
                :key="option.code"
                class="gap-2"
                :data-active="option.code === locale"
                @select="setLocale(option.code)"
            >
                <span class="flex-1" :dir="option.dir">{{
                    option.native
                }}</span>
                <Check
                    v-if="option.code === locale"
                    class="size-4 text-brand"
                />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
