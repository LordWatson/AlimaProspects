<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { CircuitBoard, Info } from '@lucide/vue';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useFormat } from '@/composables/useFormat';
import type { AdminProviderHealth } from '@/types';

/*
 * Provider health, and the one lever on it (Phase 19c).
 *
 * The screen pairs a **live** signal with **recent** context, and says which is which: the circuit
 * breaker's state as it is right now, and the error and latency rates from the nightly rollup. A
 * provider that started failing an hour ago therefore shows a healthy rate and an open breaker —
 * which is the right way round, and the caption says so rather than leaving an operator to
 * reconcile two numbers that look contradictory.
 *
 * **Three providers have no breaker at all** — the AI transport, the mail transport and Stripe —
 * and this page prints "not instrumented" for them rather than a green light nobody wired up. A
 * health screen that reports "closed" for something it is not watching is worse than one that
 * reports nothing, because it is believed.
 *
 * Brand (§2a): `success`/`warning`/`error` semantic tokens for state. No Signal Orange — it is
 * decorative only, and everything on this screen is a state.
 */
const props = defineProps<{
    providers: AdminProviderHealth[];
    context: { days: number; currency: string; last_rolled_up: string | null };
}>();

const { number, currency, percent, utcDate } = useFormat();

/** The provider key currently awaiting a second press. Resetting overrides a safety mechanism. */
const confirming = ref<string | null>(null);

// Escape disarms, for the same reason as the queues screen: a confirmation with no way back is a
// destructive label left sitting under the operator's cursor.
useEventListener(document, 'keydown', (event: KeyboardEvent): void => {
    if (event.key === 'Escape') {
        confirming.value = null;
    }
});

function reset(key: string): void {
    if (confirming.value !== key) {
        confirming.value = key;

        return;
    }

    router.post(
        '/admin/providers/reset',
        { provider: key },
        {
            preserveScroll: true,
            onFinish: () => {
                confirming.value = null;
            },
        },
    );
}

function stateOf(provider: AdminProviderHealth): {
    label: string;
    variant: 'success' | 'error' | 'outline';
} {
    if (!provider.instrumented) {
        return { label: 'not instrumented', variant: 'outline' };
    }

    return provider.open
        ? { label: 'open — calls refused', variant: 'error' }
        : { label: 'closed', variant: 'success' };
}
</script>

<template>
    <Head title="Operations · providers" />

    <div class="flex flex-col gap-8">
        <header class="max-w-2xl">
            <p
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
            >
                Platform
            </p>
            <h1 class="mt-1 text-2xl font-semibold text-foreground">
                Provider health
            </h1>
            <p class="mt-2 text-sm text-muted-foreground">
                The breaker's state is <strong>live</strong>; the rates are the
                last {{ props.context.days }} complete days from the nightly
                rollup. So a provider that started failing an hour ago shows a
                healthy rate and an open circuit — that pairing is the useful
                one, not a contradiction.
            </p>
        </header>

        <div
            class="flex items-start gap-3 rounded-2xl border border-info/30 bg-info/12 px-4 py-3"
        >
            <Info class="mt-0.5 size-5 shrink-0 text-info" />
            <p class="text-sm text-foreground">
                The circuit breaker is <strong>advisory by design</strong>. It
                lives in the cache so it holds across queue workers, it sheds
                load rather than guaranteeing anything, and its failure counter
                expires with the cooldown — so a quiet provider reads as zero
                failures rather than as its history. Read it as a signal, never
                as truth.
            </p>
        </div>

        <ul class="grid gap-4 lg:grid-cols-2">
            <li v-for="provider in providers" :key="provider.key">
                <Card class="h-full shadow-none">
                    <CardContent class="flex h-full flex-col gap-4 px-5 py-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2
                                    class="text-lg font-semibold text-foreground"
                                >
                                    {{ provider.label }}
                                </h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ provider.impact }}
                                </p>
                            </div>
                            <Badge :variant="stateOf(provider).variant">
                                {{ stateOf(provider).label }}
                            </Badge>
                        </div>

                        <dl
                            class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-4"
                        >
                            <div v-if="provider.instrumented">
                                <dt
                                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                                >
                                    Failures
                                </dt>
                                <!--
                                  An em dash, not a zero. The counter expires with the cooldown
                                  and the type allows null, so `?? 0` would print a reassuring
                                  "0 / 0" for a figure nobody has.
                                -->
                                <dd class="text-foreground tabular-nums">
                                    {{
                                        provider.failures_recorded === null
                                            ? '—'
                                            : number(provider.failures_recorded)
                                    }}
                                    /
                                    {{
                                        provider.threshold === null
                                            ? '—'
                                            : number(provider.threshold)
                                    }}
                                </dd>
                            </div>

                            <div v-if="provider.metered">
                                <dt
                                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                                >
                                    Calls
                                </dt>
                                <dd class="text-foreground tabular-nums">
                                    {{
                                        provider.calls === null
                                            ? '—'
                                            : number(provider.calls)
                                    }}
                                </dd>
                            </div>

                            <div v-if="provider.metered">
                                <dt
                                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                                >
                                    Failure rate
                                </dt>
                                <!--
                                  An em dash, not 0%: a provider nobody called is unused rather
                                  than healthy, and the distinction is the whole of Reporting's
                                  honesty rule applied here.
                                -->
                                <dd class="text-foreground tabular-nums">
                                    {{
                                        provider.failure_rate === null
                                            ? '—'
                                            : percent(provider.failure_rate, 1)
                                    }}
                                </dd>
                            </div>

                            <div v-if="provider.metered">
                                <dt
                                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                                >
                                    Spend
                                </dt>
                                <dd class="text-foreground tabular-nums">
                                    {{
                                        provider.cost_minor === null
                                            ? '—'
                                            : currency(
                                                  provider.cost_minor,
                                                  props.context.currency,
                                              )
                                    }}
                                </dd>
                            </div>

                            <div v-if="provider.mean_latency_ms !== null">
                                <dt
                                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                                >
                                    Mean latency
                                </dt>
                                <dd class="text-foreground tabular-nums">
                                    {{ number(provider.mean_latency_ms) }}ms
                                </dd>
                            </div>
                        </dl>

                        <p
                            v-if="!provider.metered"
                            class="text-sm text-muted-foreground"
                        >
                            No ledger of its own in this product — a failed send
                            is recorded on the message and a Stripe error on the
                            subscription — so there are no call or cost figures
                            here.
                        </p>

                        <div
                            v-if="provider.instrumented"
                            class="mt-auto flex flex-wrap items-center gap-3"
                        >
                            <Button
                                :variant="
                                    confirming === provider.key
                                        ? 'default'
                                        : 'outline'
                                "
                                size="sm"
                                @click="reset(provider.key)"
                            >
                                <CircuitBoard class="size-4" />
                                {{
                                    confirming === provider.key
                                        ? 'Yes, close the circuit'
                                        : 'Reset breaker'
                                }}
                            </Button>
                            <p
                                v-if="confirming === provider.key"
                                role="status"
                                class="text-xs text-warning"
                            >
                                This overrides the protection that stops us
                                hammering a struggling provider. Audited.
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </li>
        </ul>

        <footer class="text-xs text-muted-foreground">
            <p>
                Rates read from the rollup as at
                {{
                    props.context.last_rolled_up
                        ? utcDate(props.context.last_rolled_up)
                        : 'never — it has not run'
                }}
                (UTC). A cross-tenant scan of the AI ledger is the query the
                rollup exists to avoid, which is why these are last night's
                figures rather than this minute's.
            </p>
        </footer>
    </div>
</template>
