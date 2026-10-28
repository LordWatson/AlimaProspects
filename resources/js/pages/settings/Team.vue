<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Loader2, Mail, Trash2, UserPlus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type RoleOption = { value: string; label: string };
type Member = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    is_you: boolean;
};
type Invitation = {
    id: number;
    email: string;
    role: string;
    expires_at: string | null;
    is_expired: boolean;
};
type TeamData = {
    id: number;
    name: string;
    timezone: string;
    locale: string;
    market: string;
};
type Can = {
    updateTeam: boolean;
    manageMembers: boolean;
    deleteTeam: boolean;
};

const props = defineProps<{
    team: TeamData;
    members: Member[];
    invitations: Invitation[];
    roleOptions: RoleOption[];
    can: Can;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Team settings', href: '/settings/team' }],
    },
});

const localeOptions = [
    { value: 'en', label: 'English' },
    { value: 'ar', label: 'العربية (Arabic)' },
];
const marketOptions = [
    { value: 'uk', label: 'United Kingdom' },
    { value: 'uae', label: 'United Arab Emirates' },
];

function roleLabel(value: string | null): string {
    return props.roleOptions.find((r) => r.value === value)?.label ?? '—';
}

function changeRole(member: Member, event: Event): void {
    const role = (event.target as HTMLSelectElement).value;
    router.patch(
        `/settings/team/members/${member.id}`,
        { role },
        { preserveScroll: true },
    );
}

function removeMember(member: Member): void {
    if (!window.confirm(`Remove ${member.name} from the team?`)) {
        return;
    }
    router.delete(`/settings/team/members/${member.id}`, {
        preserveScroll: true,
    });
}

function resendInvitation(invitation: Invitation): void {
    router.post(
        `/settings/team/invitations/${invitation.id}/resend`,
        {},
        { preserveScroll: true },
    );
}

function revokeInvitation(invitation: Invitation): void {
    router.delete(`/settings/team/invitations/${invitation.id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Team settings" />

    <h1 class="sr-only">Team settings</h1>

    <div class="space-y-10">
        <!-- Team details -->
        <section class="space-y-6">
            <Heading
                variant="small"
                title="Team"
                description="Your team's name and regional defaults. These shape how dates, language and compliance behave across Alima."
            />

            <Form
                action="/settings/team"
                method="patch"
                class="space-y-6"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="name">Team name</Label>
                    <Input
                        id="name"
                        name="name"
                        :default-value="team.name"
                        :disabled="!can.updateTeam"
                        required
                        autocomplete="organization"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-6 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="locale">Language</Label>
                        <select
                            id="locale"
                            name="locale"
                            :disabled="!can.updateTeam"
                            class="h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50"
                        >
                            <option
                                v-for="o in localeOptions"
                                :key="o.value"
                                :value="o.value"
                                :selected="o.value === team.locale"
                            >
                                {{ o.label }}
                            </option>
                        </select>
                        <InputError :message="errors.locale" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="market">Market</Label>
                        <select
                            id="market"
                            name="market"
                            :disabled="!can.updateTeam"
                            class="h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50"
                        >
                            <option
                                v-for="o in marketOptions"
                                :key="o.value"
                                :value="o.value"
                                :selected="o.value === team.market"
                            >
                                {{ o.label }}
                            </option>
                        </select>
                        <InputError :message="errors.market" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="timezone">Timezone</Label>
                    <Input
                        id="timezone"
                        name="timezone"
                        :default-value="team.timezone"
                        :disabled="!can.updateTeam"
                        required
                    />
                    <InputError :message="errors.timezone" />
                </div>

                <div v-if="can.updateTeam" class="flex items-center gap-3">
                    <Button
                        :disabled="processing"
                        data-test="update-team-button"
                    >
                        <Loader2
                            v-if="processing"
                            class="size-4 animate-spin"
                        />
                        Save changes
                    </Button>
                </div>
            </Form>
        </section>

        <!-- Members -->
        <section class="space-y-4">
            <Heading
                variant="small"
                title="Members"
                description="Everyone who can work in this team, and what they're allowed to do."
            />

            <Card>
                <CardContent class="divide-y p-0">
                    <div
                        v-for="member in members"
                        :key="member.id"
                        class="flex flex-wrap items-center justify-between gap-3 p-4"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                {{ member.name }}
                                <span
                                    v-if="member.is_you"
                                    class="text-muted-foreground"
                                    >(you)</span
                                >
                            </p>
                            <p class="truncate text-sm text-muted-foreground">
                                {{ member.email }}
                            </p>
                        </div>

                        <div class="flex items-center gap-2">
                            <select
                                v-if="can.manageMembers && !member.is_you"
                                :value="member.role ?? ''"
                                aria-label="Member role"
                                class="h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                @change="changeRole(member, $event)"
                            >
                                <option
                                    v-for="o in roleOptions"
                                    :key="o.value"
                                    :value="o.value"
                                >
                                    {{ o.label }}
                                </option>
                            </select>
                            <Badge v-else variant="secondary">{{
                                roleLabel(member.role)
                            }}</Badge>

                            <Button
                                v-if="can.manageMembers && !member.is_you"
                                variant="ghost"
                                size="icon"
                                aria-label="Remove member"
                                @click="removeMember(member)"
                            >
                                <Trash2 class="size-4 text-destructive" />
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </section>

        <!-- Invitations -->
        <section v-if="can.manageMembers" class="space-y-4">
            <Heading
                variant="small"
                title="Invite teammates"
                description="Send an invitation by email. They'll join the moment they accept — let's grow the team."
            />

            <Form
                action="/settings/team/invitations"
                method="post"
                class="flex flex-wrap items-end gap-3"
                reset-on-success
                v-slot="{ errors, processing }"
            >
                <div class="grid flex-1 gap-2" style="min-width: 16rem">
                    <Label for="invite-email">Email address</Label>
                    <Input
                        id="invite-email"
                        name="email"
                        type="email"
                        placeholder="teammate@company.com"
                        required
                    />
                    <InputError :message="errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="invite-role">Role</Label>
                    <select
                        id="invite-role"
                        name="role"
                        class="h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    >
                        <option
                            v-for="o in roleOptions.filter(
                                (r) => r.value !== 'owner',
                            )"
                            :key="o.value"
                            :value="o.value"
                        >
                            {{ o.label }}
                        </option>
                    </select>
                    <InputError :message="errors.role" />
                </div>
                <Button :disabled="processing" data-test="invite-member-button">
                    <UserPlus class="size-4" />
                    Send invite
                </Button>
            </Form>

            <Card v-if="invitations.length > 0">
                <CardHeader>
                    <CardTitle class="text-base">Pending invitations</CardTitle>
                    <CardDescription
                        >Invitations waiting to be accepted.</CardDescription
                    >
                </CardHeader>
                <CardContent class="divide-y p-0">
                    <div
                        v-for="invitation in invitations"
                        :key="invitation.id"
                        class="flex flex-wrap items-center justify-between gap-3 p-4"
                    >
                        <div class="min-w-0">
                            <p
                                class="flex items-center gap-2 truncate font-medium"
                            >
                                <Mail class="size-4 text-muted-foreground" />
                                {{ invitation.email }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ roleLabel(invitation.role) }}
                                <span
                                    v-if="invitation.is_expired"
                                    class="text-destructive"
                                >
                                    · expired</span
                                >
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                @click="resendInvitation(invitation)"
                                >Resend</Button
                            >
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="revokeInvitation(invitation)"
                                >Revoke</Button
                            >
                        </div>
                    </div>
                </CardContent>
            </Card>
        </section>

        <!-- Create a new team -->
        <section id="create-team" class="space-y-4">
            <Heading
                variant="small"
                title="Start another team"
                description="Create a separate workspace — useful for a different brand, client or market."
            />

            <Form
                action="/teams"
                method="post"
                class="flex flex-wrap items-end gap-3"
                reset-on-success
                v-slot="{ errors, processing }"
            >
                <div class="grid flex-1 gap-2" style="min-width: 16rem">
                    <Label for="new-team-name">Team name</Label>
                    <Input
                        id="new-team-name"
                        name="name"
                        placeholder="e.g. Acme UAE"
                        required
                    />
                    <InputError :message="errors.name" />
                </div>
                <Button
                    variant="outline"
                    :disabled="processing"
                    data-test="create-team-button"
                    >Create team</Button
                >
            </Form>
        </section>
    </div>
</template>
