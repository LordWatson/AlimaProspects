<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { PanelLeft } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Toaster } from '@/components/ui/sonner';
import AdminSidebar from '@/layouts/admin/AdminSidebar.vue';
import type { AdminNavSection } from '@/types';

/*
 * The internal panel's chrome (Phase 19a, ADR 0014).
 *
 * **Deliberately distinct from the tenant app, and deliberately inside the brand.** An operator
 * must never mistake the panel for a customer's screen — the panel reads across every tenant, and a
 * moment's confusion about which one you are in is how the wrong record gets edited. So the
 * distinction is carried by a full-bleed Deep Ink shell, DM Mono labels and a tighter content
 * surface (§2a), not by a colour nobody chose: no new hues, no Signal Orange, no second type stack.
 *
 * **English only, by decision.** An internal tool carries no EN/AR or RTL obligation, so there is
 * no `t()` and no `dir` juggling here. Saying so is the difference between a decision and an
 * omission — the tenant app and the mobile app remain bilingual (ADR 0007).
 *
 * The UTC label in the topbar is not decoration. `useFormat` renders dates in the *active team's*
 * timezone, and an operator belongs to no team, so every timestamp in the panel is UTC. An
 * operations tool that silently rendered one tenant's timestamps in another's zone would be worse
 * than one that showed raw ISO strings.
 */
const page = usePage();

const navigation = computed(
    () => (page.props.navigation ?? []) as AdminNavSection[],
);

const operator = computed(() => page.props.auth.user);
</script>

<template>
    <div class="min-h-svh bg-ink text-cream lg:flex lg:gap-3 lg:p-3">
        <aside
            class="hidden w-[17rem] shrink-0 overflow-hidden rounded-3xl lg:block"
        >
            <AdminSidebar :navigation="navigation" />
        </aside>

        <div
            class="flex min-h-svh min-w-0 flex-1 flex-col bg-background text-foreground lg:min-h-0 lg:rounded-3xl"
        >
            <header
                class="flex h-16 shrink-0 items-center gap-3 border-b border-border px-4 sm:px-6"
            >
                <Sheet>
                    <SheetTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="lg:hidden"
                            aria-label="Menu"
                        >
                            <PanelLeft class="size-5" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent
                        side="left"
                        class="w-[17rem] border-0 bg-ink p-0 text-cream"
                    >
                        <SheetTitle class="sr-only">Admin menu</SheetTitle>
                        <AdminSidebar :navigation="navigation" />
                    </SheetContent>
                </Sheet>

                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                >
                    Alima operations
                </p>

                <div class="ms-auto flex items-center gap-3">
                    <span
                        class="hidden font-mono text-xs tracking-eyebrow text-muted-foreground uppercase sm:inline"
                        title="Every timestamp in the panel is UTC — an operator belongs to no team, so there is no team timezone to render in."
                    >
                        UTC
                    </span>
                    <span
                        v-if="operator"
                        class="hidden text-sm text-muted-foreground sm:inline"
                    >
                        {{ operator.name }}
                    </span>
                    <Button variant="outline" size="sm" as-child>
                        <Link href="/dashboard">Leave the panel</Link>
                    </Button>
                </div>
            </header>

            <main class="flex-1 overflow-y-auto px-4 py-6 sm:px-6">
                <slot />
            </main>
        </div>

        <Toaster />
    </div>
</template>
