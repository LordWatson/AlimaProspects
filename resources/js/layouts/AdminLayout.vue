<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { PanelLeft, Search } from '@lucide/vue';
import { logout } from '@/routes';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Toaster } from '@/components/ui/sonner';
import AdminCommandPalette from '@/components/admin/AdminCommandPalette.vue';
import AdminSidebar from '@/layouts/admin/AdminSidebar.vue';

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

const operator = computed(() => page.props.auth.user);

/**
 * The ⌘K palette, mounted once here rather than per page.
 *
 * The topbar's button is the discoverable half: a keyboard shortcut nobody is told about is a
 * feature for whoever wrote it. The palette itself owns the shortcut.
 */
const palette = ref<InstanceType<typeof AdminCommandPalette> | null>(null);

/** macOS says ⌘K and everybody else says Ctrl K, and the hint should say the right one. */
const isMac = computed(
    () =>
        typeof navigator !== 'undefined' &&
        /mac|iphone|ipad/i.test(navigator.platform || navigator.userAgent),
);

/**
 * Whether this operator also belongs to a team — in other words, whether the product has anything
 * to show them. The seeded operator does not; a real one, promoted after registering, does.
 */
const hasTeam = computed(() => page.props.auth.currentTeam !== null);
</script>

<template>
    <div class="min-h-svh bg-ink text-cream lg:flex lg:gap-3 lg:p-3">
        <aside
            class="hidden w-[17rem] shrink-0 overflow-hidden rounded-3xl lg:block"
        >
            <AdminSidebar />
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
                        <AdminSidebar />
                    </SheetContent>
                </Sheet>

                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                >
                    Alima operations
                </p>

                <!-- The discoverable half of ⌘K. Fifty-five destinations is a search problem, and
                     a search nobody can find is a rail with extra steps. -->
                <Button
                    variant="outline"
                    size="sm"
                    class="ms-4 hidden items-center gap-2 text-muted-foreground sm:flex"
                    data-test="open-palette"
                    @click="palette?.show()"
                >
                    <Search class="size-4" aria-hidden="true" />
                    <span>Go to…</span>
                    <kbd
                        class="rounded border border-border px-1.5 py-0.5 font-mono text-xs"
                        >{{ isMac ? '⌘' : 'Ctrl' }} K</kbd
                    >
                </Button>

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
                    <!-- **"Leave the panel" only leads somewhere when there is somewhere to
                         go.** An operator belongs to no team by design, and the product's dashboard
                         resolves one — so for the seeded operator this button was a link to a 404.
                         Signing out is the honest alternative, and it is what that account wants
                         next anyway. -->
                    <Button
                        v-if="hasTeam"
                        variant="outline"
                        size="sm"
                        as-child
                        data-test="leave-the-panel"
                    >
                        <Link href="/dashboard">Leave the panel</Link>
                    </Button>
                    <Button
                        v-else
                        variant="outline"
                        size="sm"
                        as-child
                        data-test="operator-logout"
                    >
                        <Link :href="logout()" as="button">Sign out</Link>
                    </Button>
                </div>
            </header>

            <main class="flex-1 overflow-y-auto px-4 py-6 sm:px-6">
                <slot />
            </main>
        </div>

        <AdminCommandPalette ref="palette" />

        <Toaster />
    </div>
</template>
