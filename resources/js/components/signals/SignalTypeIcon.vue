<script setup lang="ts">
/**
 * The icon a signal type wears.
 *
 * The **one** place a `SignalIcon` key becomes a component. The key is an allowlist on the server
 * (`Signals\Enums\SignalIcon`, ADR 0018 §8) precisely so this map can be exhaustive: a definition
 * is authored by a customer, and an arbitrary string here would render nothing. `Record<SignalIcon,
 * …>` makes `vue-tsc` fail the build if a case is added to the enum and forgotten here, which is the
 * whole reason the set is closed.
 *
 * Decorative by default. Brand §5: an icon in UI is always paired with a label, so the label is the
 * accessible name and the glyph is `aria-hidden` — repeating it would make a screen reader say the
 * type's name twice.
 */
import {
    Building2,
    FileText,
    Handshake,
    Landmark,
    Plane,
    Satellite,
    TrendingUp,
    TriangleAlert,
    Trophy,
    Users,
} from '@lucide/vue';
import { computed, type Component } from 'vue';
import type { SignalIcon } from '@/types';

const props = withDefaults(
    defineProps<{
        name: SignalIcon;
        /** A label, when the glyph has to stand on its own. Omit it wherever text sits beside it. */
        label?: string | null;
    }>(),
    { label: null },
);

const icons: Record<SignalIcon, Component> = {
    satellite: Satellite,
    handshake: Handshake,
    'trending-up': TrendingUp,
    plane: Plane,
    'building-2': Building2,
    users: Users,
    'file-text': FileText,
    'triangle-alert': TriangleAlert,
    landmark: Landmark,
    trophy: Trophy,
};

// Falls back rather than rendering nothing: a key the server somehow sent that this build does not
// know is a missing glyph, not a missing card.
const icon = computed<Component>(() => icons[props.name] ?? Satellite);
</script>

<template>
    <component
        :is="icon"
        :aria-hidden="label ? undefined : 'true'"
        :aria-label="label ?? undefined"
        :role="label ? 'img' : undefined"
    />
</template>
