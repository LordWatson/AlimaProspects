<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Info, OctagonAlert, TriangleAlert, X } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import type { AnnouncementBannerData } from '@/types';

/*
 * What the platform wants this tenant to know (Phase 19b).
 *
 * In the **app shell**, like the impersonation banner, and for the same reason: a notice added per
 * screen is a notice missing from the screen nobody remembered. The data is a shared prop, so a
 * banner appears and disappears with the announcement rather than with a controller's diligence.
 *
 * **Bilingual, unlike the panel that writes it.** The copy arrives already resolved for this
 * reader's locale by `AnnouncementBoard`, with an English fallback — so there is no `t()` on the
 * title or the body (they are an operator's words, not keys), while the chrome around them is
 * translated like everything else a customer sees.
 *
 * Because that fallback exists, each line carries **its own `lang` and `dir`**. A reader who asked
 * for Arabic and got the English fallback is reading LTR text inside an RTL page, which otherwise
 * right-aligns it and puts its punctuation in the wrong place; and the `[lang="ar"]` type rules
 * (Tajawal, no letter-spacing) do not reach an element by inheritance when the page is English.
 * `dir="auto"` is the backstop for mixed-script copy.
 *
 * Brand (§2a): `status.*` tokens throughout — `info` for a notice, `warning` for a heads-up,
 * `error` for something actually wrong. Signal Orange appears nowhere: every state here is a
 * status, and Signal Orange is decorative only. The status colour carries the icon, the border and
 * the tint; the **words are `text-foreground`**, because status-coloured text on a tint of the same
 * status does not clear AA at this size.
 *
 * And the level is never colour alone (WCAG 1.4.1): each level has its own icon and a visually
 * hidden word, so a screen reader can tell a critical outage from a heads-up.
 */
const page = usePage();
const { t } = useI18n();

const announcements = computed<AnnouncementBannerData[]>(
    () => (page.props.announcements ?? []) as AnnouncementBannerData[],
);

/**
 * Tone per level. Written out rather than interpolated, because Tailwind cannot see a class name
 * that only exists at runtime — `bg-${variant}/12` would be purged from the build.
 */
const tones: Record<
    AnnouncementBannerData['variant'],
    { wrapper: string; accent: string; icon: typeof Info; label: string }
> = {
    info: {
        wrapper: 'border-info/30 bg-info/12',
        accent: 'text-info',
        icon: Info,
        label: 'Notice',
    },
    warning: {
        wrapper: 'border-warning/30 bg-warning/12',
        accent: 'text-warning',
        icon: TriangleAlert,
        label: 'Heads-up',
    },
    error: {
        wrapper: 'border-destructive/30 bg-destructive/12',
        accent: 'text-destructive',
        icon: OctagonAlert,
        label: 'Problem',
    },
};

function direction(locale: string): 'rtl' | 'ltr' {
    return locale === 'ar' ? 'rtl' : 'ltr';
}

function dismiss(id: number): void {
    router.post(
        `/announcements/${id}/dismiss`,
        {},
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <div v-if="announcements.length > 0" class="flex flex-col">
        <!--
          `role="status"` with a polite live region, not `alert`: these are standing notices a
          screen reader should announce on arrival, not interruptions to re-announce on every visit.
        -->
        <div
            v-for="announcement in announcements"
            :key="announcement.id"
            role="status"
            aria-live="polite"
            class="flex flex-wrap items-start gap-x-3 gap-y-2 border-b px-4 py-3 text-sm sm:px-6"
            :class="tones[announcement.variant].wrapper"
        >
            <component
                :is="tones[announcement.variant].icon"
                class="mt-0.5 size-4 shrink-0"
                :class="tones[announcement.variant].accent"
                aria-hidden="true"
            />

            <div class="min-w-0 flex-1">
                <!--
                  The level in words, for a reader who cannot see the icon or the colour. Visually
                  hidden because sighted readers have both.
                -->
                <span class="sr-only"
                    >{{ t(tones[announcement.variant].label) }}:
                </span>

                <!-- An operator's words, already in this reader's language. Not a translation key. -->
                <p
                    class="font-semibold text-foreground"
                    :lang="announcement.title_locale"
                    :dir="direction(announcement.title_locale)"
                >
                    {{ announcement.title }}
                </p>
                <p
                    v-if="announcement.body"
                    class="mt-0.5 break-words text-foreground/90"
                    :lang="announcement.body_locale"
                    :dir="direction(announcement.body_locale)"
                >
                    {{ announcement.body }}
                </p>
            </div>

            <Button
                v-if="announcement.dismissible"
                variant="ghost"
                size="icon-sm"
                type="button"
                :aria-label="t('Dismiss :title', { title: announcement.title })"
                @click="dismiss(announcement.id)"
            >
                <X class="size-4" />
            </Button>
        </div>
    </div>
</template>
