<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { KeyRound, LogOut, MailCheck, ShieldAlert, UserCog } from '@lucide/vue';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useFormat } from '@/composables/useFormat';

/*
 * One person's operations page (Phase 19a).
 *
 * Every action here is a **verb with a side effect**, which is exactly why this is a bespoke screen
 * rather than editable fields on the `users` resource: a form that looked like it could set
 * `password` or `email` would be an invitation to do the one thing the panel must not.
 *
 * The rule, stated on the page so an operator never has to infer it: **we never know or choose
 * somebody's credential.** A reset sends a link. A two-factor reset clears the secret so they can
 * enrol again. Session and token revocation take access away and never grant it.
 *
 * Impersonation is here rather than on the team page because it is a property of the person, and
 * refused outright for another operator — impersonating a colleague would put their powers behind
 * this session with their name on the audit trail.
 */
const props = defineProps<{
    user: {
        id: number;
        name: string;
        email: string;
        admin_role: string | null;
        admin_role_label: string | null;
        verified: boolean;
        two_factor: boolean;
        created_at: string | null;
        tokens: number;
    };
    teams: {
        id: number;
        name: string;
        role: string | null;
        suspended: boolean;
    }[];
}>();

const { dateTime } = useFormat();

const confirmingImpersonation = ref(false);

function post(action: string): void {
    router.post(`/admin/users/${props.user.id}/${action}`, undefined, {
        preserveScroll: true,
    });
}

function impersonate(): void {
    router.post(`/admin/users/${props.user.id}/impersonate`);
}
</script>

<template>
    <Head :title="user.name" />

    <div class="flex flex-col gap-8">
        <header class="min-w-0">
            <p
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
            >
                Person · {{ user.id }}
            </p>
            <h1 class="mt-1 text-2xl font-semibold text-foreground">
                {{ user.name }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">{{ user.email }}</p>
            <p class="mt-2 flex flex-wrap items-center gap-2">
                <Badge v-if="user.admin_role_label" variant="info">
                    {{ user.admin_role_label }}
                </Badge>
                <Badge :variant="user.verified ? 'success' : 'warning'">
                    {{ user.verified ? 'Verified' : 'Unverified' }}
                </Badge>
                <Badge :variant="user.two_factor ? 'success' : 'secondary'">
                    {{ user.two_factor ? 'Two-factor on' : 'No two-factor' }}
                </Badge>
                <Badge variant="outline">
                    {{ user.tokens }} API token(s)
                </Badge>
                <Badge v-if="user.created_at" variant="outline">
                    Joined {{ dateTime(user.created_at) }}
                </Badge>
            </p>
        </header>

        <section class="flex flex-col gap-3" aria-label="Account actions">
            <h2 class="text-lg font-semibold text-foreground">Account</h2>

            <div class="flex flex-wrap gap-2">
                <Button variant="outline" @click="post('password-reset')">
                    <KeyRound class="size-4" />
                    Send a reset link
                </Button>
                <Button variant="outline" @click="post('two-factor-reset')">
                    <ShieldAlert class="size-4" />
                    Clear two-factor
                </Button>
                <Button variant="outline" @click="post('revoke-sessions')">
                    <LogOut class="size-4" />
                    Sign out everywhere
                </Button>
                <Button variant="outline" @click="post('revoke-tokens')">
                    <KeyRound class="size-4" />
                    Revoke API tokens
                </Button>
                <Button
                    v-if="!user.verified"
                    variant="outline"
                    @click="post('verify-email')"
                >
                    <MailCheck class="size-4" />
                    Mark address verified
                </Button>
            </div>

            <p class="max-w-prose text-xs text-muted-foreground">
                We never set or read somebody's password. A reset sends them a
                link; clearing two-factor lets them enrol a new device. Nothing
                here grants access — it only takes it away or invites them to
                set it up again.
            </p>
        </section>

        <section class="flex flex-col gap-3" aria-label="Impersonation">
            <h2 class="text-lg font-semibold text-foreground">Impersonate</h2>

            <div
                v-if="user.admin_role"
                class="rounded-2xl border border-border bg-muted/40 px-5 py-4 text-sm text-muted-foreground"
            >
                This account is a platform operator, so it cannot be
                impersonated. Doing so would put their powers behind your
                session with their name on the audit trail.
            </div>

            <div
                v-else
                class="flex flex-col gap-3 rounded-2xl border border-border bg-card px-5 py-4"
            >
                <p class="max-w-prose text-sm text-muted-foreground">
                    You will see the product exactly as they do. Sending,
                    billing, exports and credentials are blocked throughout, the
                    session ends by itself after an hour, and both its start and
                    its stop are recorded against this tenant.
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-if="!confirmingImpersonation"
                        variant="outline"
                        @click="confirmingImpersonation = true"
                    >
                        <UserCog class="size-4" />
                        Sign in as {{ user.name }}
                    </Button>
                    <template v-else>
                        <!-- Takes focus, because the button it replaced had it. -->
                        <Button v-focus @click="impersonate">
                            Yes, sign in as them
                        </Button>
                        <Button
                            variant="ghost"
                            @click="confirmingImpersonation = false"
                        >
                            Cancel
                        </Button>
                    </template>
                </div>
            </div>
        </section>

        <section class="flex flex-col gap-3" aria-label="Teams">
            <h2 class="text-lg font-semibold text-foreground">Teams</h2>

            <div
                v-if="teams.length === 0"
                class="rounded-2xl border border-border bg-muted/40 px-5 py-4 text-sm text-muted-foreground"
            >
                This account belongs to no team — which is what an operator
                account looks like.
            </div>

            <div
                v-else
                class="overflow-x-auto rounded-2xl border border-border"
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Team</TableHead>
                            <TableHead>Role</TableHead>
                            <TableHead>State</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="team in teams" :key="team.id">
                            <TableCell class="font-medium">
                                <Link
                                    :href="`/admin/teams/${team.id}`"
                                    class="rounded-sm underline-offset-4 hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {{ team.name }}
                                </Link>
                            </TableCell>
                            <TableCell class="text-muted-foreground">
                                {{ team.role ?? '—' }}
                            </TableCell>
                            <TableCell>
                                <Badge
                                    :variant="
                                        team.suspended ? 'warning' : 'success'
                                    "
                                >
                                    {{
                                        team.suspended ? 'Suspended' : 'Active'
                                    }}
                                </Badge>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </section>
    </div>
</template>
