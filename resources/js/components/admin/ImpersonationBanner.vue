<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ShieldAlert } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/composables/useFormat';
import type { ImpersonationBannerData } from '@/types';

/*
 * "You are signed in as somebody else" — on every tenant screen, for as long as it is true
 * (Phase 19a).
 *
 * It lives in the **app shell**, not on the pages, which is the only placement that makes the
 * promise keepable: a banner added per screen is a banner missing from the screen nobody
 * remembered. The data comes from the shared `impersonation` prop, written by `ShareImpersonation`
 * on every web request, so the banner appears and disappears with the session rather than with a
 * controller's diligence.
 *
 * Brand (§2a): `status.warning`, because an impersonated session is a **state** — and Signal Orange
 * is decorative only, never a status colour, however much a warning stripe might want it. The
 * wording is plain about what is blocked, so an operator does not discover the restriction by
 * meeting a refusal.
 *
 * `role="status"` with a polite live region rather than `alert`: it is a standing condition a
 * screen reader should announce on arrival, not an interruption to re-announce on every visit.
 */
const page = usePage();
const { date } = useFormat();

/*
 * **Formatted in UTC explicitly**, which is the one place in this component that has to be
 * deliberate. `useFormat` renders in `auth.currentTeam?.timezone`, and while impersonating the
 * current team is the *customer's* — so `dateTime()` would print their local time under a label
 * saying UTC. The option spread in `date()` lets the timezone be overridden; the panel is UTC
 * throughout because an operator belongs to no team.
 */
function expiresAt(value: string): string {
    return date(value, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });
}

const banner = computed(
    () => (page.props.impersonation ?? null) as ImpersonationBannerData | null,
);

function stop(): void {
    const target = banner.value?.stop_url;

    if (target) {
        router.post(target);
    }
}
</script>

<template>
    <!--
      An English, LTR island on a screen that may be Arabic and RTL. This is the only admin
      component that renders on a **tenant** screen, and the panel is English by decision — so
      rather than translating an operator-only message into a customer-facing bundle, the element
      declares its own language and direction. Without `dir`, the sentence would right-align and its
      punctuation would flip inside an Arabic layout.
    -->
    <div
        v-if="banner"
        role="status"
        aria-live="polite"
        lang="en"
        dir="ltr"
        class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-warning/30 bg-warning/12 px-4 py-2 text-sm text-foreground sm:px-6"
    >
        <!-- `status.warning` carries the state on the icon, the border and the lead-in; the body
             copy is `text.primary`, because warning-on-warning/12 is a bare 4.5:1 and this sentence
             is the part somebody actually has to read. -->
        <ShieldAlert class="size-4 shrink-0 text-warning" aria-hidden="true" />

        <p class="min-w-0 flex-1">
            <span class="font-semibold text-warning">
                You are viewing this account as its owner.
            </span>
            <span>
                Sending, billing, exports and credentials are blocked.
                <template v-if="banner.expires_at">
                    This ends at {{ expiresAt(banner.expires_at) }} UTC.
                </template>
            </span>
        </p>

        <Button variant="outline" size="sm" type="button" @click="stop">
            Stop impersonating
        </Button>
    </div>
</template>
